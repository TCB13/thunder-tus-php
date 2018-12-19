<?php

error_reporting(E_ALL);

$url     = "http://tus.test/wall-express.jpg";
$chkAlgo = "crc32";
$file    = realpath("wall.jpg");
$fileChk = base64_encode(hash_file($chkAlgo, $file, true));
$fileLen = filesize($file);

// Upload file - Express + CrossCheck - Multiple Parts
$chunkLen = 100000;
$fh = fopen($file, "rb");
while (!feof($fh)) {

	$pointer = ftell($fh);
	print "Uploading chunk $pointer -> " . ($pointer + $chunkLen) . "\n";
	$chunk = fread($fh, $chunkLen);
	$chunkChk = base64_encode(hash($chkAlgo, $chunk, true));

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
	curl_setopt($ch, CURLOPT_HEADER, true);

	$headers = [
		"Content-Type"         => "application/offset+octet-stream",
		"Tus-Resumable"        => "1.0.0",
		"Upload-Offset"        => $pointer,
		"Upload-Checksum"      => "$chkAlgo $chunkChk", // For the current part
		// ThunderTUS
		"CrossCheck"           => "true",
		"Express"              => "true",
		"Upload-Length"        => $fileLen,
		"Upload-CrossChecksum" => "$chkAlgo $fileChk"// For the entire file
	];
	$fheaders = [];
	foreach ($headers as $key => $value)
		$fheaders[] = $key . ": " . $value;
	curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

	$result = curl_exec($ch);
	$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if (curl_errno($ch))
		echo "Error: " . curl_error($ch);
	curl_close($ch);

	if ($httpcode == 410) {
		print "CrossCheck Failed!";
		break;
	}

	// Move pointer to server offset if there was an error uploading a particular part
	if ($httpcode !== 204) {
		print "Chunk upload failed at $pointer retrying...";

		$headers = explode("\r\n", $result);
		$headers = array_map(function ($item) {
			return explode(": ", $item);
		}, $headers);
		$header = array_filter($headers, function ($value) {
			if ($value[0] === "Upload-Offset")
				return true;
		});

		if (empty($header) && $httpcode == 409) {
			print "Error file already uploaded?";
			break;
		}

		$serverOffset = (int)array_shift($header)[1];

		rewind($fh);
		fseek($fh, $serverOffset);
		print " new pointer at " . ftell($fh) . "\n";
	}

}
fclose($fh);
