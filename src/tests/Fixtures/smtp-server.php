<?php

// Disposable Compose sink: internal network only, never forwards messages.
$server = stream_socket_server('tcp://0.0.0.0:1025', $code, $error);
if (! $server) {
    throw new RuntimeException('Cannot start synthetic SMTP sink.');
}
while ($client = stream_socket_accept($server, -1)) {
    stream_set_timeout($client, 5);
    fwrite($client, "220 recovery synthetic SMTP\r\n");
    while (($line = fgets($client)) !== false) {
        if (trim($line) === 'DATA') {
            fwrite($client, "354 Send message\r\n");
            $data = '';
            while (($part = fgets($client)) !== false && $part !== ".\r\n") {
                $data .= $part;
            }
            if (file_put_contents('/capture/messages.jsonl', json_encode(['data' => base64_encode($data)])."\n", FILE_APPEND | LOCK_EX) === false) {
                fwrite($client, "451 Capture unavailable\r\n");
                throw new RuntimeException('Synthetic SMTP capture could not be retained.');
            }
            fwrite($client, "250 Captured locally\r\n");
        } elseif (trim($line) === 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        } else {
            fwrite($client, "250 OK\r\n");
        }
    }
    fclose($client);
}
