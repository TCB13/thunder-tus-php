<?php

namespace ThunderTUS\Store;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class S3 extends StorageBackend
{
    private $client;

    private $bucket;
    private $prefix;
    private $containerPrefix = "container.";

    public function __construct(S3Client $client, string $bucket = "test", string $prefix = "tus-temp")
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->prefix = $prefix;
    }

    /** Implement StoreInterface */
    public function exists(string $name): bool
    {
        // In S3 assume that if a container exists, the file exists as well
        return $this->containerExists($name);
    }

    public function create(string $name): bool
    {
        $response = $this->client->createMultipartUpload([
            "Bucket"      => $this->bucket,
            "Key"         => $this->prefix . "/" . $name,
            "ContentType" => null
        ]);

        // Save the uploadId into our container so we can continue the upload later
        $container           = $this->containerFetch($name);
        $container->uploadid = $response["UploadId"];
        $this->containerUpdate($name, $container);

        return true;
    }

    public function getSize(string $name): int
    {
        $container = $this->containerFetch($name);
        if (!isset($container->uploadid)) {
            return 0;
        }

        $result = $this->client->listParts([
            "Bucket"   => $this->bucket,
            "Key"      => $this->prefix . "/" . $name,
            "UploadId" => $container->uploadid
        ]);
        $result = $result->toArray();
        if (!isset($result["Parts"])) {
            return 0; // This is a new file
        }

        $size = (int)array_sum(array_column($result["Parts"], "Size"));
        return $size;
    }

    public function append(string $name, $data): bool
    {
        // So we know what part number we're uploading and uploadid
        $container = $this->containerFetch($name);
        if (!isset($container->uploadid)) {
            return false;
        }

        if (!isset($container->parts)) {
            $partNumber = 1;
            $parts      = [];
            // Find the mimetype of the file by reading the begining of the first part
            $container->mimetype = $this->mimetypeFromStream($data);
        } else {
            $parts      = (array)$container->parts;
            $lastPart   = end($parts)->PartNumber;
            $partNumber = ($lastPart + 1);
        }

        // Upload the part
        try {
            $result = $this->client->uploadPart([
                "Bucket"     => $this->bucket,
                "Key"        => $this->prefix . "/" . $name,
                "UploadId"   => $container->uploadid,
                "PartNumber" => $partNumber,
                "Body"       => $data
            ]);
        } catch (S3Exception $e) {
            return false;
        }

        // Update the container with the new part info
        $partMeta         = [
            "PartNumber" => $partNumber,
            "ETag"       => $result["ETag"],
        ];
        $parts[]          = $partMeta;
        $container->parts = $parts;
        $this->containerUpdate($name, $container);

        return true;
    }

    public function store(string $name, $data): bool
    {
        // Store the entire file in one request, used for single part uploads
        try {
            $this->client->putObject([
                "Bucket"      => $this->bucket,
                "Key"         => $this->prefix . "/" . $name,
                "ContentType" => $this->mimetypeFromStream($data),
                "Body"        => $data,
            ]);
        } catch (S3Exception $e) {
            return false;
        }
        return true;
    }

    protected function mimetypeFromStream($stream): string
    {
        $finfo    = new \finfo(FILEINFO_MIME);
        $mimetype = $finfo->buffer(fread($stream, 100));
        $mimetype = explode(";", $mimetype)[0];
        rewind($stream);
        return $mimetype;
    }

    public function delete(string $name): bool
    {
        $container = $this->containerFetch($name);
        if (!isset($container->uploadid)) {
            return false;
        }

        try {
            $this->client->abortMultipartUpload([
                "Bucket"   => $this->bucket,
                "Key"      => $this->prefix . "/" . $name,
                "UploadId" => $container->uploadid
            ]);
        } catch (S3Exception $e) {
            // Just ignore the error, file is probably already gone
        }

        return true;
    }

    public function completeAndFetch(string $name, string $destinationDirectory, bool $removeAfter = true): bool
    {
        if (!$this->complete($name)) {
            return false;
        }

        // Download the completed file
        $filePath = self::normalizePath($destinationDirectory) . $name;
        $this->client->getObject([
            "Bucket" => $this->bucket,
            "Key"    => $this->prefix . "/" . $name,
            "SaveAs" => $filePath
        ]);

        // Remove the S3 file if requested
        if ($removeAfter) {
            $this->client->deleteObject([
                "Bucket" => $this->bucket,
                "Key"    => $this->prefix . "/" . $name,
            ]);
            $this->containerDelete($name);
        }

        return true;
    }

    public function completeAndStream(string $name, bool $removeAfter = true)
    {
        if (!$this->complete($name)) {
            return false;
        }

        // Download the completed file
        $result = $this->client->getObject([
            "Bucket" => $this->bucket,
            "Key"    => $this->prefix . "/" . $name,
        ]);

        $body  = $result["Body"];
        $final = fopen("php://temp", "r+");
        if (is_resource($body)) {
            stream_copy_to_stream($body, $final);
            fclose($body);
        } else {
            fwrite($final, $body);
        }
        rewind($final);

        if ($removeAfter) {
            $this->client->deleteObject([
                "Bucket" => $this->bucket,
                "Key"    => $this->prefix . "/" . $name,
            ]);
            $this->containerDelete($name);
        }

        return $final;
    }

    public function complete(string $name): bool
    {
        try {
            $container = $this->containerFetch($name);
        } catch (S3Exception $e) {
            // Single file uploads doesn't have container
            // no further file processing is required!
            return true;
        }
        if (!isset($container->uploadid) || !isset($container->parts)) {
            return false;
        }
        $parts = json_decode(json_encode($container->parts), true);

        // Merge all the parts of the file by completing the upload
        try {

            $result = $this->client->completeMultipartUpload([
                "Bucket"          => $this->bucket,
                "Key"             => $this->prefix . "/" . $name,
                "UploadId"        => $container->uploadid,
                "MultipartUpload" => [
                    "Parts" => $parts,
                ],

            ]);

            // Update the mimetype of the file. This value was stored in the container when the first
            // part was uploaded
            if (isset($container->mimetype)) {
                $result = $this->client->copyObject([
                    "Bucket"            => $this->bucket,
                    "CopySource"        => $this->bucket . "/" . $this->prefix . "/" . $name,
                    "Key"               => $this->prefix . "/" . $name,
                    "MetadataDirective" => "REPLACE",
                    "ContentType"       => $container->mimetype
                ]);
            }

        } catch (\Exception $e) {
            // In case of error just discard all the parts and the container
            $this->delete($name);
            $this->containerDelete($name);
            return false;
        }

        $this->containerDelete($name);
        return true;
    }

    public function containerExists(string $name): bool
    {
        try {
            $result = $this->client->headObject([
                "Bucket" => $this->bucket,
                "Key"    => $this->prefix . "/" . $this->containerPrefix . $name
            ]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
        }
        return true;
    }

    public function containerCreate(string $name, ?\stdClass $data = null): bool
    {
        $data = (array)$data;
        if ($data === null) {
            $data = [];
        }

        $this->client->putObject([
            "Bucket" => $this->bucket,
            "Key"    => $this->prefix . "/" . $this->containerPrefix . $name,
            "Body"   => json_encode($data)
        ]);

        return true;
    }

    public function containerUpdate(string $name, \stdClass $data): bool
    {
        return $this->containerCreate($name, $data);
    }

    public function containerFetch(string $name): \stdClass
    {
        $result = $this->client->getObject([
            "Bucket" => $this->bucket,
            "Key"    => $this->prefix . "/" . $this->containerPrefix . $name,
        ]);
        $result = (string)$result["Body"];
        return json_decode($result);
    }

    public function containerDelete(string $name): bool
    {
        $this->client->deleteObject([
            "Bucket" => $this->bucket,
            "Key"    => $this->prefix . "/" . $this->containerPrefix . $name,
        ]);
        return true;
    }
}
