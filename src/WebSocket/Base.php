<?php
namespace Therac\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Base implements MessageComponentInterface {
    use Handle, Emit;

    protected $clients;
    protected $Therac;

    private $activeFile = ['file' => null, 'line' => 0];
    private $activeSearch = ['isOpen' => false, 'search' => '', 'results' => [], 'uniqID' => ''];

    const REPLInput = 'REPLInput', REPLOutput = 'REPLOutput', REPLError = 'REPLError', REPLStdout = 'REPLStdout';
    const REPLPrompt = 'therac> ';

    private $REPLState = [
        ['data' => self::REPLPrompt, 'type' => self::REPLInput]
    ];

    function __construct($Therac) {
        $this->Therac = $Therac;
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->uniqID = uniqid();

        $this->emitActiveContext();
        $this->emitActiveStack();
        $this->emitActiveFileSearch();
        $this->emitUniqID($conn);
        $this->emitBreakOnExceptionSet();

        foreach ($this->REPLState as $line) {
            $this->baseEmit($line['type'], [$line['data']], [$conn]);
        }

        if ($this->activeFile['file']) {
            $this->emitFileContents($this->activeFile['file']);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $msg = json_decode($msg, true);
        if (isset($msg) && is_array($msg) && isset($msg['event']) && isset($msg['data'])) {
            try {
                $method = new \ReflectionMethod(__CLASS__, 'handle' . $msg['event']);
                array_unshift($msg['data'], $from);
                if ($method->getNumberOfParameters() === count($msg['data'])) {
                    $method->setAccessible(true);
                    $method->invokeArgs($this, $msg['data']);
                } else {
                    echo "Parameter count mismatch for: {$msg['event']}\n";
                }
            } catch (\Exception $e) {
                echo "No such WebSocket handler: {$e->getMessage()}\n";
            }
        } else {
            echo "Invalid WebSocket Input\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}
