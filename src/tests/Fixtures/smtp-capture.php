<?php

// One-message loopback SMTP sink. Never relays mail or binds a public interface.
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
if (! $server) {
    throw new RuntimeException('Cannot start local SMTP capture.');
}
echo json_encode(['port' => (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1)])."\n";
$client = stream_socket_accept($server, 10);
if (! $client) {
    exit(1);
}
stream_set_timeout($client, 5);
fwrite($client, "220 localhost test capture\r\n");
$recipient = '';
while (($line = fgets($client)) !== false) {
    if (str_starts_with($line, 'EHLO') || str_starts_with($line, 'HELO')) {
        fwrite($client, "250 localhost\r\n");
    } elseif (str_starts_with($line, 'MAIL FROM:')) {
        fwrite($client, "250 OK\r\n");
    } elseif (str_starts_with($line, 'RCPT TO:')) {
        $recipient = trim(substr($line, 8));
        fwrite($client, "250 OK\r\n");
    } elseif (trim($line) === 'DATA') {
        fwrite($client, "354 Send message\r\n");
        $data = '';
        while (($part = fgets($client)) !== false && $part !== ".\r\n") {
            $data .= str_starts_with($part, '..') ? substr($part, 1) : $part;
        }
        echo json_encode(['recipient' => $recipient, 'data' => base64_encode($data)])."\n";
        if (($argv[1] ?? '') === 'hold') {
            sleep(20);
        }
        fwrite($client, "250 Captured locally\r\n");
        fclose($client);
        fclose($server);
        exit(0);
    } else {
        fwrite($client, "250 OK\r\n");
    }
}
exit(1);
