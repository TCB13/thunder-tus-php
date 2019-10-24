<?php

namespace ThunderTUS;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use ThunderTUS\Store\StoreInterface;

class Server
{

    const PROTOCOL_VERSION = "1.0.0";

    protected $uploadMaxFileSize = 50000000;
    protected $apiPath = "";
    /**
     * @var StoreInterface
     */
    protected $backend = null;

    /**
     * @var \Psr\Http\Message\ServerRequestInterface
     */
    protected $request;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    protected $response;
    protected $stream;
    protected $streamURI;
    protected $file;
    protected $location;

    protected $extCrossCheck = false;
    protected $extExpress = false;

    /**
     * Thunder TUS Server constructor.
     *
     * @param ?\Psr\Http\Message\ServerRequestInterface $request
     * @param ?\Psr\Http\Message\ResponseInterface      $response
     * @param string $streamURI A stream URI from where to get uploaded data.
     *
     */
    public function __construct(?ServerRequestInterface $request = null, ?ResponseInterface $response = null, string $streamURI = "php://input")
    {
        $this->streamURI = $streamURI;
        if ($request !== null && $response !== null) {
            $this->loadHTTPInterfaces($request, $response);
        }
    }

    public function loadHTTPInterfaces(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->request  = $request;
        $this->response = $response;

        // Detect the ThunderTUS CrossCheck extension
        if ($this->request->getHeaderLine("CrossCheck") == true) {
            $this->extCrossCheck = true;
        }

        // Detect the ThunderTUS Express extension
        if ($this->request->getHeaderLine("Express") == true) {
            $this->extExpress = true;
        }
    }

    public function setStorageBackend($backend)
    {
        if (!$backend instanceof StoreInterface) {
            throw new ThunderTUSException("Storage backend must implement " . StoreInterface::class);
        }
        $this->backend = $backend;
        return $this;
    }

    public function getStorageBackend()
    {
        return $this->backend;
    }

    /**
     * Completes an upload and fetches the finished file from the backend storage.
     * This method abstracts backend storage file retrieval in a way that the user doesn't
     * need to know what backend storage is being used at all times.
     * This is useful when the TUS Server is provided by some kind of Service Provider in a
     * dependency injection context.
     *
     * @param string $filename             Name of your file
     * @param string $destinationDirectory Where to place the finished file
     * @param bool   $removeAfter          Remove the temporary files after this operation
     *
     * @return bool
     */
    public function completeAndFetch(string $filename, string $destinationDirectory, bool $removeAfter = true): bool
    {
        return $this->backend->completeAndFetch($filename, $destinationDirectory, $removeAfter);
    }

    /**
     * Completes an upload and returns the finished file in the form of a stream.
     * Useful when you want to upload the file to another system without writing
     * it to the disk most of the time.
     * This method uses PHP's tmp stream to merge the file parts. Adjust it accordingly.
     *
     * @param string $filename    Name of your file
     * @param bool   $removeAfter Remove the temporary files after this operation
     *
     * @return bool
     */
    public function completeAndStream(string $filename, bool $removeAfter = true)
    {
        return $this->backend->completeAndStream($filename, $removeAfter);
    }

    /**
     * Completes an upload without fetching it. The file will be placed in the
     * same backend storage you're using for the temporary part upload.
     * Useful when you want to keep the finished file in the same storage backend
     * you're using for the temporary part upload.
     * This method uses PHP's tmp stream to merge the file parts. Adjust it accordingly.
     *
     * @param string $filename    Name of your file
     *
     * @return bool
     */
    public function complete(string $name): bool
    {
        return $this->backend->complete($name);
    }

    /**
     * Handles the incoming request.
     *
     * @throws \ThunderTUS\ThunderTUSException
     */
    public function handle(): Server
    {
        if (!isset($this->request) || !isset($this->response)) {
            throw new ThunderTUSException("You must set an '\Psr\Http\Message\ServerRequestInterface' and a '\Psr\Http\Message\ResponseInterface' implementation via the ThunderTUS constructor or by calling 'loadHTTPInterfaces()'.");
        }

        // Add global headers to the response
        $this->response = $this->response->withHeader("Tus-Resumable", "1.0.0")
            ->withHeader("Tus-Max-Size", $this->uploadMaxFileSize)
            ->withHeader("Cache-Control", "no-store");

        // Check if a storage backend is set
        if ($this->backend === null) {
            throw new ThunderTUSException("You must set a storage backend before handling requests.");
        }

        // Call handlers
        $method = "handle" . $this->request->getMethod();
        if (!method_exists($this, $method)) {
            throw new ThunderTUSException("Invalid HTTP request method. Not TUS or ThunderTUS compliant.");
        }

        // Check if this server supports the client protocol version
        if ($this->request->getHeaderLine("Tus-Resumable") != self::PROTOCOL_VERSION) {
            $this->response = $this->response->withStatus(412);
            return $this;
        }

        // Gather the filename from the last part of the URL and set the resource location
        $this->apiPath  = rtrim($this->apiPath, "/") . "/";
        $url            = $this->request->getUri();
        $this->file     = str_replace([
            "\\",
            "/"
        ], "", substr($url, strrpos($url, $this->apiPath) + strlen($this->apiPath)));
        $this->location = $this->apiPath . $this->file;

        // Replicate the input stream so we can control its pointer
        $this->stream = fopen('php://temp', 'w+');
        stream_copy_to_stream(fopen($this->streamURI, 'r'), $this->stream);
        rewind($this->stream);

        $this->response = $this->$method();

        return $this;
    }

    /**
     * Handle POST requests.
     *
     * Creates new files on the server and caches its expected size.
     *
     * If the client uses the ThunderTUS CrossCheck extension this method will also cache the final file
     * checksum - the checksum of the entire file.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handlePOST(): ResponseInterface
    {

        // If the already exists we can't create it
        if ($this->backend->exists($this->file)) {
            return $this->response->withStatus(409);
        }

        // Get the total upload expected length
        $length = $this->request->getHeaderLine("Upload-Length");
        if ($length === "") // Note: Some PSR7 implementations discard headers set to 0.
        {
            return $this->response->withStatus(400);
        }

        // Check for the size of the entire upload
        if ($this->uploadMaxFileSize > 0 && $this->uploadMaxFileSize < $length) {
            return $this->response->withStatus(413);
        }

        // Create empty cache container
        $cache         = new \stdClass();
        $cache->length = $length;

        // Extension Thunder TUS CrossCheck: get complete upload checksum
        if ($this->extCrossCheck) {
            $supportedAlgos  = $this->backend->supportsCrossCheck() ? $this->backend->getCrossCheckAlgoritms() : hash_algos();
            $cache->checksum = self::parseChecksum($this->request->getHeaderLine("Upload-CrossChecksum"));
            if ($cache->checksum === false || !in_array($cache->checksum->algorithm, $supportedAlgos)) {
                return $this->response->withStatus(400);
            }
        }

        // Create an empty file to store the upload and save the cache container
        $this->backend->containerCreate($this->file, $cache);
        $this->backend->create($this->file);

        return $this->response->withStatus(201)
            ->withHeader("Location", $this->location);
    }

    /**
     * Handle HEAD requests.
     *
     * Informs the client about the status of a file. If the file exists, the number of bytes (offset) already
     * stored in the server is also provided.
     *
     * If the client uses the ThunderTUS Express extension this method will also create new files bypassing
     * the need to call POST.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleHEAD(): ResponseInterface
    {

        // Extension Thunder TUS Express: create the file on HEAD request
        if ($this->extExpress) {
            $this->response = $this->handlePOST();
            $code           = $this->response->getStatusCode();
            if ($code !== 201 && $code !== 409) {
                return $this->response;
            }
            $this->response = $this->response->withHeader("Location", $this->location);
        } else { // For Standard TUS
            if (!$this->backend->exists($this->file)) {
                return $this->response->withStatus(404);
            }
        }

        // Standard TUS HEAD response
        $localSize = $this->backend->getSize($this->file);

        return $this->response->withStatus(200)
            ->withHeader("Upload-Offset", $localSize);

    }

    /**
     * Handle PATCH requests.
     *
     * Receives a chuck of a file and stores it on the server. Implements the checksum extension to validate
     * the integrity of the chunk.
     *
     * If the client uses the ThunderTUS Express extension this method will also create new files bypassing
     * the need to call POST/HEAD.
     *
     * If the client uses the ThunderTUS CrossCheck extension this method will also verify the final file
     * checksum (after all chunks are received) against checksum previously stored in the cache.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handlePATCH(): ResponseInterface
    {

        $offset = $this->request->getHeaderLine("Upload-Offset");
        if ($offset === "") // Note: Some PSR7 implementations discard headers set to 0.
        {
            return $this->response->withStatus(400);
        }

        // Check if the server supports the proposed checksum algorithm
        $supportedAlgos = $this->backend->supportsCrossCheck() ? $this->backend->getCrossCheckAlgoritms() : hash_algos();
        $checksum       = self::parseChecksum($this->request->getHeaderLine("Upload-Checksum"));
        if ($checksum === false || !in_array($checksum->algorithm, $supportedAlgos)) {
            return $this->response->withStatus(400);
        }

        // Extension Thunder TUS Express: create the file on PATCH request
        if ($this->extExpress && $offset == 0) {
            $this->response = $this->handlePOST();
            $code           = $this->response->getStatusCode();
            if ($code !== 201 && $code !== 409) {
                return $this->response;
            }
            if ($code === 409 && !$this->backend->containerExists($this->file)) // Avoid overwriting completed uploads
            {
                return $this->response;
            }
        } else { // For Standard TUS
            if (!$this->backend->exists($this->file)) {
                return $this->response->withStatus(404);
            }
        }

        // Check if the current stored file offset is different from the proposed upload offset
        // This ensures we're getting the file block by block in the right order and nothing is missing
        $fsize = $this->backend->getSize($this->file);
        if ($fsize != $offset) {
            return $this->response->withStatus(409)
                ->withHeader("Upload-Offset", $fsize);
        }

        // Compare proposed checksum with the received data checksum
        $hashContext = hash_init($checksum->algorithm);
        hash_update_stream($hashContext, $this->stream);
        $localChecksum = base64_encode(hash_final($hashContext, true));
        if ($localChecksum !== $checksum->value) {
            return $this->response->withStatus(460, "Checksum Mismatch")
                ->withHeader("Upload-Offset", $fsize);
        }

        rewind($this->stream);
        $this->backend->append($this->file, $this->stream);
        $localSize = $this->backend->getSize($this->file);

        // Detect when the upload is complete
        $cache = $this->backend->containerFetch($this->file);
        if ($cache->length <= $localSize) {
            // Extension Thunder TUS CrossCheck: verify if the uploaded file is as expected or delete it
            if ($this->extCrossCheck && $this->backend->supportsCrossCheck()) {
                if (!$this->backend->crossCheck($this->file, $cache->checksum->algorithm, $cache->checksum->value)) {
                    $this->backend->delete($this->file);
                    $this->backend->containerDelete($this->file);
                    return $this->response->withStatus(410);
                }
            }
        }

        if ($this->extCrossCheck) {
            $this->response = $this->response->withHeader("Location", $this->location);
        }

        // File uploaded successfully!
        return $this->response->withStatus(204)
            ->withHeader("Upload-Offset", $localSize);

    }

    /**
     * Handle DELETE requests.
     *
     * Remove an existing file from the server and its associated resources. Useful when the client
     * decides to abort an upload.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleDELETE(): ResponseInterface
    {
        if (!$this->backend->exists($this->file)) {
            return $this->response->withStatus(404);
        }
        $this->backend->delete($this->file);

        if ($this->backend->containerExists($this->file)) {
            $this->backend->containerDelete($this->file);
        }

        return $this->response->withStatus(204);
    }

    /**
     * Handle OPTIONS requests.
     *
     * Return information about the server's current configuration. Useful to get the protocol
     * version, the maximum upload file size and supported extensions.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function handleOPTIONS(): ResponseInterface
    {
        return $this->response->withStatus(204)
            ->withHeader("Tus-Version", self::PROTOCOL_VERSION)
            ->withHeader("Tus-Max-Size", $this->uploadMaxFileSize)
            ->withHeader("Tus-Extension", "creation,checksum,termination,crosscheck,express");
    }

    protected static function parseChecksum(string $value = "")
    {
        $value = explode(" ", $value);
        if (count($value) !== 2) {
            return false;
        }
        $value = array_map("trim", $value);
        return (object)[
            "algorithm" => $value[0],
            "value"     => $value[1]
        ];
    }

    /**
     * Returns a PSR-7 compliant Response to sent to the client.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Returns the underlying stream resource where the data sent to the server was stored.
     *
     * @param bool $rewind If the stream should be rewinded before returned.
     *
     * @return bool|resource
     */
    public function getStream(bool $rewind = true)
    {
        if ($rewind) {
            rewind($this->stream);
        }
        return $this->stream;
    }

    public function getUploadMaxFileSize(): int
    {
        return $this->uploadMaxFileSize;
    }

    public function getApiPath(): string
    {
        return $this->apiPath;
    }

    /**
     * Set the maximum allowed size for uploads. Setting this to 0 will disable this limitation.
     *
     * @param int $uploadMaxFileSize
     *
     * @return \ThunderTUS\Server
     */
    public function setUploadMaxFileSize(int $uploadMaxFileSize): Server
    {
        $this->uploadMaxFileSize = $uploadMaxFileSize;
        return $this;
    }

    /**
     * @param mixed $apiPath
     *
     * @return \ThunderTUS\Server
     */
    public function setApiPath($apiPath): Server
    {
        $this->apiPath = $apiPath;
        return $this;
    }
}
