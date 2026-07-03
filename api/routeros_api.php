<?php
class RouterOSAPI {
    private $socket;
    private $timeout = 5;

    public function connect($host, $user, $pass, $port = 8728, $timeout = 5) {
        $this->timeout = $timeout;

        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$this->socket) {
            throw new Exception("Cannot connect to RouterOS API: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, $timeout);

        $reply = $this->comm("/login", [
            "name" => $user,
            "password" => $pass
        ]);

        if (!isset($reply["done"]) || $reply["done"] !== true) {
            throw new Exception("RouterOS login failed");
        }

        return true;
    }

    public function comm($command, $params = []) {
        $this->writeWord($command);

        foreach ($params as $key => $value) {
            if (substr($key, 0, 1) === "?") {
                $this->writeWord($key . "=" . $value);
            } else {
                $this->writeWord("=" . $key . "=" . $value);
            }
        }

        $this->writeWord("");

        $response = [
            "re" => [],
            "done" => false,
            "trap" => []
        ];

        while (true) {
            $word = $this->readWord();

            if ($word === false) {
                break;
            }

            if ($word === "") {
                continue;
            }

            if ($word === "!re") {
                $row = [];

                while (true) {
                    $w = $this->readWord();

                    if ($w === false || $w === "") {
                        break;
                    }

                    if (substr($w, 0, 1) === "=") {
                        $parts = explode("=", substr($w, 1), 2);

                        if (count($parts) === 2) {
                            $row[$parts[0]] = $parts[1];
                        }
                    }
                }

                $response["re"][] = $row;
            }

            if ($word === "!trap") {
                $trap = [];

                while (true) {
                    $w = $this->readWord();

                    if ($w === false || $w === "") {
                        break;
                    }

                    if (substr($w, 0, 1) === "=") {
                        $parts = explode("=", substr($w, 1), 2);

                        if (count($parts) === 2) {
                            $trap[$parts[0]] = $parts[1];
                        }
                    }
                }

                $response["trap"][] = $trap;
            }

            if ($word === "!done") {
                while (true) {
                    $w = $this->readWord();

                    if ($w === false || $w === "") {
                        break;
                    }
                }

                $response["done"] = true;
                break;
            }
        }

        return $response;
    }

    private function writeWord($word) {
        $len = strlen($word);

        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr(($len >> 8) | 0x80));
            fwrite($this->socket, chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            fwrite($this->socket, chr(($len >> 16) | 0xC0));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            fwrite($this->socket, chr(($len >> 24) | 0xE0));
            fwrite($this->socket, chr(($len >> 16) & 0xFF));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0));
            fwrite($this->socket, chr(($len >> 24) & 0xFF));
            fwrite($this->socket, chr(($len >> 16) & 0xFF));
            fwrite($this->socket, chr(($len >> 8) & 0xFF));
            fwrite($this->socket, chr($len & 0xFF));
        }

        fwrite($this->socket, $word);
    }

    private function readWord() {
        $c = fread($this->socket, 1);

        if ($c === "" || $c === false) {
            return false;
        }

        $len = ord($c);

        if (($len & 0x80) == 0x00) {
        } elseif (($len & 0xC0) == 0x80) {
            $len = (($len & ~0xC0) << 8) + ord(fread($this->socket, 1));
        } elseif (($len & 0xE0) == 0xC0) {
            $len = (($len & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } elseif (($len & 0xF0) == 0xE0) {
            $len = (($len & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } else {
            $len = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }

        if ($len === 0) {
            return "";
        }

        return fread($this->socket, $len);
    }

    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}
