<?php

namespace ThunderTUS;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

class Server
{

	const PROTOCOL_VERSION    = "1.0.0";
	const CHECKSUM_ALGORITHMS = [
		"crc32",
		"md5",
		"sha1",
		"sha256",
		"sha512",
	];

	protected $uploadMaxFileSize = 50000000;
	protected $uploadDir = null;
	protected $apiPath = "";

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
	 * @param \Psr\Http\Message\ServerRequestInterface $request
	 * @param \Psr\Http\Message\ResponseInterface      $response
	 * @param string                                   $streamURI A stream URI from where to get uploaded data.
	 *
	 */
	public function __construct(ServerRequestInterface $request, ResponseInterface $response, string $streamURI = "php://input")
	{

		$this->request   = $request;
		$this->response  = $response;
		$this->streamURI = $streamURI;

		// Detect the ThunderTUS CrossCheck extension
		if ($this->request->getHeaderLine("CrossCheck") == true)
			$this->extCrossCheck = true;

		// Detect the ThunderTUS Express extension
		if ($this->request->getHeaderLine("Express") == true)
			$this->extExpress = true;

		// Add global headers to the response
		$this->response = $this->response->withHeader("Tus-Resumable", "1.0.0")
		                                 ->withHeader("Tus-Max-Size", $this->uploadMaxFileSize)
		                                 ->withHeader("Cache-Control", "no-store");
	}

	/**
	 * Handles the incoming request.
	 *
	 * @throws \ThunderTUS\ThunderTUSException
	 */
	public function handle(): Server
	{

		// Check the upload directory
		if (!is_dir($this->uploadDir))
			throw new ThunderTUSException("Invalid upload directory. Path wasn't set, it doesn't exist or it isn't a directory.");

		// Call handlers
		$method = "handle" . $this->request->getMethod();
		if (!method_exists($this, $method))
			throw new ThunderTUSException("Invalid HTTP request method. Not TUS or ThunderTUS compliant.");

		// Check if this server supports the client protocol version
		if ($this->request->getHeaderLine("Tus-Resumable") != self::PROTOCOL_VERSION) {
			$this->response = $this->response->withStatus(412);
			return $this;
		}

		// Gather the filename from the last part of the URL and set the resource location
		$this->apiPath   = rtrim($this->apiPath, "/") . "/";
		$this->uploadDir = rtrim(realpath($this->uploadDir), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
		$url             = $this->request->getUri();
		$this->file      = str_replace(["\\", "/"], "", substr($url, strrpos($url, $this->apiPath) + strlen($this->apiPath)));
		$this->location  = $this->apiPath . $this->file;
		$this->file      = $this->uploadDir . $this->file;

		// Replicate the input stream so we can control its pointer
		$inStream     = fopen($this->streamURI, "r");
		$this->stream = fopen("php://temp", "w+");
		stream_copy_to_stream($inStream, $this->stream);
		fclose($inStream);
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
		if (is_file($this->file))
			return $this->response->withStatus(409);

		// Get the total upload expected length
		$length = $this->request->getHeaderLine("Upload-Length");
		if ($length === "")
			$length = 0; // @TODO: Some PSR7 implementations discard headers set to 0. The TUS protocol demands we sent HTTP 400.
			//return $this->response->withStatus(400);

		// Check for the size of the entire upload
		if ($this->uploadMaxFileSize > 0 && $this->uploadMaxFileSize < $length)
			return $this->response->withStatus(413);

		// Create empty cache container
		$cache         = new \stdClass();
		$cache->length = $length;

		// Extension Thunder TUS CrossCheck: get complete upload checksum
		if ($this->extCrossCheck) {
			$cache->checksum = self::parseChecksum($this->request->getHeaderLine("Upload-CrossChecksum"));
			if ($cache->checksum === false)
				return $this->response->withStatus(400);
		}

		// Create an empty file to store the upload and save the cache container
		touch($this->file);
		file_put_contents($this->file . ".cachecontainer", \json_encode($cache));

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
			if ($code !== 201 && $code !== 409)
				return $this->response;
			$this->response = $this->response->withHeader("Location", $this->location);
		}
		else { // For Standard TUS
			if (!is_file($this->file))
				return $this->response->withStatus(404);
		}

		// Standard TUS HEAD response
		$localSize = filesize($this->file);

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
		if ($offset === "")
			$offset = 0; // @TODO: Some PSR7 implementations discard headers set to 0. The TUS protocol demands we sent HTTP 400.
			//return $this->response->withStatus(400);

		// Check if the server supports the proposed checksum algorithm
		$checksum = self::parseChecksum($this->request->getHeaderLine("Upload-Checksum"));
		if ($checksum === false)
			return $this->response->withStatus(400);

		// Extension Thunder TUS Express: create the file on PATCH request
		if ($this->extExpress && $offset == 0) {
			$this->response = $this->handlePOST();
			$code = $this->response->getStatusCode();
			if ($code !== 201 && $code !== 409)
				return $this->response;
			if ($code === 409 && !file_exists($this->file . ".cachecontainer")) // Avoid overwriting completed uploads
				return $this->response;
		}
		else { // For Standard TUS
			if (!is_file($this->file))
				return $this->response->withStatus(404);
		}

		// Check if the current stored file offset is different from the proposed upload offset
		// This ensures we're getting the file block by block in the right order and nothing is missing
		if (filesize($this->file) != $offset) {
			return $this->response->withStatus(409)
			                      ->withHeader("Upload-Offset", filesize($this->file));
		}

		// Compare proposed checksum with the received data checksum
		$hashContext = hash_init($checksum->algorithm);
		hash_update_stream($hashContext, $this->stream);
		$localChecksum = base64_encode(hash_final($hashContext, true));
		if ($localChecksum !== $checksum->value)
			return $this->response->withStatus(460, "Checksum Mismatch")
			                      ->withHeader("Upload-Offset", filesize($this->file));

		rewind($this->stream);

		// Write the uploaded chunk to the file
		$file = fopen($this->file, "ab");
		stream_copy_to_stream($this->stream, $file);
		fclose($file);

		clearstatcache(true, $this->file);
		$localSize = filesize($this->file);

		// Detect when the upload is complete
		$cache = \json_decode(file_get_contents($this->file . ".cachecontainer"));
		if ($cache->length <= $localSize) {

			// Remove the cache container, we don't need it anymore
			unlink($this->file . ".cachecontainer");

			// Extension Thunder TUS CrossCheck: verify if the uploaded file is as expected or delete it
			if ($this->extCrossCheck) {
				$localChecksum = base64_encode(hash_file($cache->checksum->algorithm, $this->file, true));
				if ($localChecksum !== $cache->checksum->value) {
					unlink($this->file);

					return $this->response->withStatus(410);
				}
			}

		}

		if ($this->extCrossCheck)
			$this->response = $this->response->withHeader("Location", $this->location);

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
		if (!is_file($this->file))
			return $this->response->withStatus(404);

		unlink($this->file);

		$cacheStore = $this->file . ".cachecontainer";
		if (is_file($cacheStore))
			unlink($cacheStore);

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

		if ($value === "")
			return false;

		$value = \explode(" ", $value);
		if (empty($value) || !\in_array($value[0], self::CHECKSUM_ALGORITHMS) || !isset($value[1]))
			return false;

		$checksum            = new \stdClass();
		$checksum->algorithm = $value[0];
		$checksum->value     = $value[1];

		return $checksum;

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
		if ($rewind)
			rewind($this->stream);

		return $this->stream;
	}

	public function getUploadMaxFileSize(): int
	{
		return $this->uploadMaxFileSize;
	}

	public function getUploadDir(): ?string
	{
		return $this->uploadDir;
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
	 * Set the directory where Thunder-TUS should save uploads.
	 *
	 * @param string $uploadDir
	 *
	 * @return \ThunderTUS\Server
	 * @throws \ThunderTUS\ThunderTUSException
	 */
	public function setUploadDir(string $uploadDir): Server
	{

		$uploadDir = \realpath($uploadDir);
		if (!is_dir($uploadDir))
			throw new ThunderTUSException("Invalid upload directory. Path doesn't exist or it isn't a directory.");

		$this->uploadDir = $uploadDir;

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
