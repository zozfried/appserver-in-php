<?php
namespace AiP\Transport;

use AiP\Transport\LibEvent\RuntimeException;
use AiP\Transport\LibEvent\LogicException;

class LibEvent extends AbstractTransport
{
    const EV_BUFFER_READ          = 0x01;
    const EV_BUFFER_WRITE         = 0x02;
    const EV_BUFFER_EOF           = 0x10;
    const EV_BUFFER_ERROR         = 0x20;
    const EV_BUFFER_TIMEOUT       = 0x40;

    const STATE_READ              = 0x01;
    const STATE_WRITE             = 0x02;

    protected $event_base;

    protected $socket            = array();
    protected $socket_events      = array();

    protected $connections_count  = 0;
    protected $connections        = array();
    protected $connection_events  = array();
    protected $connection_buffers = array();
    protected $connection_statuses  = array();

    public $timeout = 5;

    protected $callback;

    public function __construct($addr, $callback)
    {
        if (!extension_loaded('libevent'))
            throw new LogicException('LibEvent transport requires pecl/libevent extension');

        parent::__construct($addr, $callback);
    }

    public function loop()
    {
        if (!$this->event_base = event_base_new())
            throw new RuntimeException("Can't create event base");

        $this->addSocketEvent();

        event_base_loop($this->event_base);
    }

    public function unloop()
    {
       event_base_loopexit($this->event_base);
    }

    protected function addSocket($addr)
    {
        $this->socket = stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        self::log('Socket', 'created on '.$addr);
    }

    protected function addSocketEvent()
    {
        $event = event_new();
        if (!event_set($event, $this->socket, EV_READ | EV_PERSIST, array($this, 'onEventAccept'))) {
            throw new RuntimeException("Can't set event");
        }

        if (false === event_base_set($event, $this->event_base))
            throw new RuntimeException("Can't set [{$socket_num}] event base.");

        if (false === event_add($event)) {
            throw new RuntimeException("Can't add event");
        }

        $this->socket_events = $event;
        self::log('Socket', 'event added');
    }

    public function onEventAccept($socket, $event)
    {
        $conn = $this->acceptSocket();
        $conn_num = $this->addConnection($conn);
        $this->addConnectionBuffer($conn_num);
    }

    public function onEventRead($socket, $args)
    {
        $conn_num = $args[0];
        if ($this->connection_statuses[$conn_num] != self::STATE_READ) {
            self::log('Connection', $conn_num, 'trying to write in read connection');
            $this->closeConnection($conn_num);

            return;
        }

        $buffer = $this->connection_buffers[$conn_num];
        LibEvent\Stream::setTransport($this);
        $stream = fopen('libevent-buffer://'.$conn_num, 'w+');
        self::log('Connection', $conn_num, 'buffer opened');

        self::log('Connection', $conn_num, 'request callback');
        call_user_func($this->callback, $stream);
        $this->connection_statuses[$conn_num] = self::STATE_WRITE;
    }

    public function onEventWrite($socket, $args)
    {
        $conn_num = $args[0];
        self::log('Connection', $conn_num, 'write');
        if ($this->connection_statuses[$conn_num] == self::STATE_WRITE) {
            $this->closeConnection($conn_num);
            pcntl_signal_dispatch();
        }
    }

    public function onEventError($socket, $error_mask, $args)
    {
        $conn_num = $args[0];

        if ($error_mask & self::EV_BUFFER_EOF)
            $msg = "EOF";
        if ($error_mask & self::EV_BUFFER_ERROR)
            $msg = "unknown error";
        if ($error_mask & self::EV_BUFFER_TIMEOUT)
            $msg = "timeout";

        if ($error_mask & self::EV_BUFFER_READ)
            $state = 'READ';
        elseif ($error_mask & self::EV_BUFFER_WRITE)
            $state = 'WRITE';

        self::log('Connection', $conn_num, 'Error: '.$msg.' on '.$state);

        $this->closeConnection($conn_num);
    }

    protected function acceptSocket()
    {
        $connection = stream_socket_accept($this->socket, 0);
        stream_set_blocking($this->socket, 0);

        self::log('Socket', 'accepted');

        return $connection;
    }

    protected function addConnection($connection)
    {
        $num = $this->connections_count++;
        $this->connections[$num] = $connection;
        self::log('Connection', $num, 'created');

        return $num;
    }

    protected function addConnectionBuffer($conn_num)
    {
        $buffer = event_buffer_new($this->connections[$conn_num],
                                   array($this, 'onEventRead'),
                                   array($this, 'onEventWrite'),
                                   array($this, 'onEventError'),
                                   array($conn_num));
        event_buffer_base_set($buffer, $this->event_base);
        event_buffer_timeout_set($buffer, $this->timeout, $this->timeout);
        event_buffer_enable($buffer, EV_READ | EV_WRITE | EV_PERSIST);

        self::log('Connection', $conn_num, 'buffer added');
        $this->connection_buffers[$conn_num] = $buffer;
        $this->connection_statuses[$conn_num] = self::STATE_READ;
    }

    public function closeConnection($conn_num)
    {
        $this->freeBuffer($conn_num);
        fclose($this->connections[$conn_num]);
        self::log('Connection', $conn_num, 'closed');
        unset($this->connections[$conn_num]);
        unset($this->connection_statuses[$conn_num]);
    }

    /**
     * @param $conn_id
     * @param $count
     * @return string
     */
    public function readFromBuffer($conn_id, $count)
    {
        $readed = event_buffer_read($this->connection_buffers[$conn_id], $count);
        self::log('Connection', $conn_id, 'read '.strlen($readed).' chars from buffer');

        return $readed;
    }

    public function writeToBuffer($conn_id, $data)
    {
        $result = event_buffer_write($this->connection_buffers[$conn_id], $data);
        self::log('Connection', $conn_id, 'wrote '.strlen($data).' chars to buffer');

        return $result;
    }

    protected function freeBuffer($conn_num)
    {
        event_buffer_disable($this->connection_buffers[$conn_num], EV_READ | EV_WRITE);
        event_buffer_free($this->connection_buffers[$conn_num]);
        unset($this->connection_buffers[$conn_num]);
        self::log('Connection', $conn_num, 'buffer is free. Fly, bird, fly!');
    }
}
