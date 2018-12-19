<?php

error_reporting(E_ALL);

$url     = "http://tus.test/wall.jpg";
$chkAlgo = "crc32";
$file    = realpath("wall.jpg");
$fileChk = base64_encode(hash_file($chkAlgo, $file, true));
$fileLen = filesize($file);

// Create file
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$headers = [
	"Tus-Resumable" => "1.0.0",
	"Content-Type"  => "application/offset+octet-stream",
	"Upload-Length" => $fileLen
];
$fheaders = [];
foreach ($headers as $key => $value)
	$fheaders[] = $key . ": " . $value;
curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

$result = curl_exec($ch);
if (curl_errno($ch)) {
	echo "Error: " . curl_error($ch);
	exit;
}
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 201) {
	print "File created successfully \n";
}
else {
	print "Error creating file \n";
	exit;
}
curl_close($ch);

// Get file offset/size on the server
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$headers = [
	"Tus-Resumable" => "1.0.0",
	"Content-Type"  => "application/offset+octet-stream",
];
$fheaders = [];
foreach ($headers as $key => $value)
	$fheaders[] = $key . ": " . $value;
curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

$result = curl_exec($ch);
if (curl_errno($ch))
	echo "Error: " . curl_error($ch);

$headers = explode("\r\n", $result);
$headers = array_map(function ($item) {
	return explode(": ", $item);
}, $headers);
$header = array_filter($headers, function ($value) {
	if ($value[0] === "Upload-Offset")
		return true;
});
$serverOffset = (int)array_shift($header)[1];
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200)
	print "Current file offset at server: $serverOffset \n";

if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 404) {
	print "File not found!";
	exit;
}
curl_close($ch);

// Upload whole file
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file));
curl_setopt($ch, CURLOPT_HEADER, true);
$headers = [
	"Content-Type"    => "application/offset+octet-stream",
	"Tus-Resumable"   => "1.0.0",
	"Upload-Offset"   => 0,
	"Upload-Checksum" => "$chkAlgo $fileChk",
];
$fheaders = [];
foreach ($headers as $key => $value)
	$fheaders[] = $key . ": " . $value;
curl_setopt($ch, CURLOPT_HTTPHEADER, $fheaders);

$result = curl_exec($ch);
if (curl_errno($ch))
	echo "Error: " . curl_error($ch);
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
	print "File uploaded successfully!";
	exit;
}
curl_close($ch);
