<?php
namespace AiP\Protocol;

use AiP\Protocol\SCGI\Response;

use AiP\Protocol\SCGI\LogicException;
use AiP\Protocol\SCGI\BadProtocolException;

class SCGI implements \AiP\Protocol
{
    private $stream = null;
    private $headers = null;

    public function __destruct()
    {
        if ($this->stream) {
            fclose($this->stream);
        }
        // $this->log("DeInitialized SCGI Application: ".get_class($this));
    }

    public function readRequest($stream, $remote_addr)
    {
        $this->stream = $stream;

        $len = stream_get_line($this->stream, 20, ':');

        if (false === $len) {
            throw new LogicException('error reading data');
        }

        if ('' === $len) {
            // could be bug in PHP or Lighttpd. sometimes, app just gets empty request
            // retrying
            $this->doneWithRequest();

            return false;
        }

        if (!is_numeric($len)) {
            throw new BadProtocolException('invalid protocol (expected length, got '.var_export($len, true).')');
        }

        $_headers_str = stream_get_contents($this->stream, $len);

        $_headers = explode("\0", $_headers_str); // getting headers
        $divider = stream_get_contents($this->stream, 1); // ","

        $this->headers = array();
        $first = null;
        foreach ($_headers as $element) {
            if (null === $first) {
                $first = $element;
            } else {
                $this->headers[$first] = $element;
                $first = null;
            }

        }
        unset($_headers, $first);

        if (!isset($this->headers['SCGI']) or $this->headers['SCGI'] != '1')
            throw new BadProtocolException("Request is not SCGI/1 Compliant (".var_dump($this->headers, true).")");

        if (!isset($this->headers['CONTENT_LENGTH']))
            throw new BadProtocolException("CONTENT_LENGTH header not present");

        unset($this->headers['SCGI']);
    }

    public function doneWithRequest()
    {
        if (null !== $this->stream) {
            $this->headers = null;

            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function writeResponse($response_data)
    {
        $response = new Response($this);
        $response->setStatus($response_data[0]);
        for ($i = 0, $cnt = count($response_data[1]); $i < $cnt; $i++) {
            $response->addHeader($response_data[1][$i], $response_data[1][++$i]);
        }

        $response->sendHeaders();

        // body
        if (is_string($response_data[2])) {
            $this->write($response_data[2]);
        } elseif (is_resource($response_data[2])) {
            fseek($response_data[2], 0);
            while (!feof($response_data[2])) {
                $this->write(fread($response_data[2], 1024));
            }
            fclose($response_data[2]);
        }
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getStdin()
    {
        return $this->stream;
    }

    public function write($data)
    {
        fwrite($this->stream, $data);
    }
}
