<?php
namespace Therac\Xdebug;

trait Handle {

    protected $streamOutCache = '', $streamErrorCache = '';
    protected function handleStream($msg) {
        $attributes = $msg->attributes();
        $value = (string) $msg;
        if (isset($attributes['encoding']) && $attributes['encoding'] == 'base64') {
            $value = base64_decode($value);
        }
        $value = trim($value);
        $value = str_replace("\n", "\r\n", $value);

        if ((string) $attributes['type'] === 'stdout') {
            $this->streamOutCache .= "$value \r\n";
        } else if ((string) $attributes['type'] === 'stderr') {
            $this->streamErrorCache .= "$value \r\n";
        }
    }

    protected function maybeEmitStreamCaches() {
        if (!empty($this->streamOutCache)) {
            $this->Therac->WebSocket->emitREPLStdout($this->streamOutCache);
            $this->streamOutCache = '';
        }

        if (!empty($this->streamErrorCache)) {
            $this->Therac->WebSocket->emitREPLError($this->streamErrorCache);
            $this->streamErrorCache = '';
        }
    }


    protected function handleInit($msg) {
        $attributes = $msg->attributes();

        if (in_array($attributes['fileuri'], $this->Therac->BLACKLISTED_FILES)) {
            $this->closeActiveConn();
        }

        $this->emitStdout();

        if ($this->breakOnException['enabled']) {
            $this->emitBreakOnException();
        }
        foreach ($this->breakPoints as $breakPoint) {
            $this->emitBreakpointSet($breakPoint['file'], $breakPoint['line']);
        }

        $this->emitRun();
    }

    protected function handleResponse($msg) {
        $attributes = $msg->attributes();
        $cmd = (string) $attributes['command'];

        switch ($cmd) {
        case 'run': case 'step_over': case 'step_out': case 'step_into':
            if ((string) $attributes['status'] === 'break') {
                $childAttributes = $msg->children('xdebug', true)->attributes();
                $file = str_replace('file://', "", $childAttributes['filename']);
                $line = (string) $childAttributes['lineno'];
                $this->activeBreak = [
                    'file' => $file,
                    'line' => $line,
                ];
                $this->Therac->WebSocket->emitFileContents($file);
                $this->emitStackGet();
                $this->emitContextNames(0);

                if (isset($childAttributes['exception'])) {
                    $err = $msg->children('xdebug', true);
                    $err->addAttribute('type', 'stderr');
                    $this->handleStream($err);
                }


            } else if ((string) $attributes['status'] === 'stopping') {
                $this->activeBreak = NULL;
                $this->activeStack = [];
                $this->activeContext = ['depth' => null, 'contexts' => []];

                $this->emitRun();
                $this->Therac->WebSocket->emitBreak(null, null);
                $this->Therac->WebSocket->emitActiveLineSet(null);
                $this->Therac->WebSocket->emitActiveContext();
                $this->Therac->WebSocket->emitActiveStack();
                $this->closeActiveConn();
            }
            break;
        case 'eval':
            try {
                $this->Therac->WebSocket->emitREPLOutput($this->valueResponseToString($msg->children()));
                $this->emitContextNames(0);
            } catch (\Exception $e) {
                $this->Therac->WebSocket->emitREPLError($e->getMessage());
            }
            break;
        case 'context_names':
            $this->activeContext['contexts'] = [];
            foreach ($msg->children() as $child) {
                $this->activeContext['contexts'][(string) $child['id']] = [
                    'name'   => (string) $child['name'],
                    'id'     => (string) $child['id'],
                    'values' => [],
                    'isset'  => false,
                ];
                $this->emitContextGet($child['id']);
            }
            break;
        case 'context_get':
            $contextId = (string) $msg->attributes()['context'];
            $this->activeContext['contexts'][$contextId]['isset'] = true;

            foreach ($msg->children() as $child) {
                $childAttributes = $child->attributes();
                try {
                    $this->activeContext['contexts'][$contextId]['values'][] =  [
                        'name'  => (string) $childAttributes['name'],
                        'value' => $this->valueResponseToString($child),
                    ];
                } catch (\Exception $e) {
                    echo $e->getMessage();
                }
            }

            foreach ($this->activeContext['contexts'] as $context) {
                if ($context['isset'] === false) {
                    break 2;
                }
            }
            $this->Therac->WebSocket->emitActiveContext();

            break;
        case 'stack_get':
            $this->activeStack = [];
            foreach ($msg->children() as $child) {
                $childAttributes = $child->attributes();
                $this->activeStack[] = [
                    'where' => (string) $childAttributes['where'],
                    'depth' => (string) $childAttributes['level'],
                    'file'  => str_replace('file://', "", $childAttributes['filename']),
                    'line'  => (string) $childAttributes['lineno'],
                ];
            }
            $this->Therac->WebSocket->emitActiveStack();
            break;

        case 'breakpoint_set':
            if ($this->breakOnException['transactionId'] ==  $msg->attributes()['transaction_id']) {
                $this->breakOnException['id'] = (string) $msg->attributes()['id'];
                break;
            }

            foreach ($this->breakPoints as &$breakPoint) {
                if ($breakPoint['transactionId'] == $msg->attributes()['transaction_id']) {
                    $breakPoint['id'] = (string) $msg->attributes()['id'];
                }
            }
            break;
        }
        $this->maybeEmitStreamCaches();
    }
}
