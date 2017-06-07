<?php
/**
 * Spike library
 * @author Tao <taosikai@yeah.net>
 */
namespace Spike\Buffer;

use React\Socket\ConnectionInterface;
use Spike\Exception\InvalidArgumentException;

class HttpBuffer extends Buffer
{
    protected $headers;

    protected $body;

    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
        $this->connection->on('data', [$this, 'handleData']);
    }

    public function handleData($data)
    {
        $this->headers .= $data;
        $pos = strpos($this->headers, "\r\n\r\n");
        if ($pos !== false) {
            $this->body .= substr($this->headers, $pos + 4);
            $this->headers = substr($this->headers, 0, $pos);
            $this->connection->removeListener('data', [$this, 'handleData']);

            if (preg_match("/Transfer-Encoding: ?chunked/i", $this->headers)) {
                $bodyBuffer = new ChunkedBuffer($this->connection);
                $bodyBuffer->gather(function(BufferInterface $bodyBuffer){
                    $this->body .= (string)$bodyBuffer;
                    $this->gatherComplete();
                });
            } elseif (preg_match("/Content-Length: ?(\d+)/i", $this->headers, $match)) {
                $length = $match[1];
                $furtherContentLength = $length - strlen($this->body);
                if ($furtherContentLength > 0) {
                    $bodyBuffer = new FixedLengthBuffer($this->connection, $furtherContentLength);
                    $bodyBuffer->gather(function(BufferInterface $bodyBuffer){
                        $this->body .= (string)$bodyBuffer;
                        $this->gatherComplete();
                    });
                } else {
                    $this->gatherComplete();
                }
            } else {
                $method = strstr($this->headers, ' ', true);
                if($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
                    $this->gatherComplete();
                    return;
                }
                throw new InvalidArgumentException('Bad http message');
            }
        }
    }

    protected function gatherComplete()
    {
        $this->content = $this->headers . "\r\n\r\n" . $this->body;
        parent::gatherComplete();
    }

    /**
     * @inheritdoc
     */
    public function flush()
    {
        parent::flush();
        $this->connection->on('data', [$this, 'handleData']);
    }
}