<?php

namespace App\Services;

/** Bounded ClamD INSTREAM client. Only an exact clean reply releases a file. */
class UploadScanner
{
    public function scan(string $bytes): string
    {
        $socket = @stream_socket_client('tcp://'.config('uploads.scanner_host').':3310', $code, $message, 3);
        if (! $socket) {
            return 'pending';
        }
        stream_set_timeout($socket, 10);
        try {
            $packet = "zINSTREAM\0".pack('N', strlen($bytes)).$bytes.pack('N', 0);
            while (strlen($packet) > 0) {
                $n = fwrite($socket, $packet);
                if (! $n) {
                    return 'pending';
                }$packet = substr($packet, $n);
            }
            $reply = stream_get_line($socket, 4096, "\0");
            if ($reply === 'stream: OK') {
                return 'clean';
            }

            return is_string($reply) && str_ends_with($reply, ' FOUND') ? 'rejected' : 'pending';
        } finally {
            fclose($socket);
        }
    }
}
