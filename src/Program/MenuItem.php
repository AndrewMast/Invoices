<?php

namespace Invoices\Program;

class MenuItem {
    public function __construct($message, $action) {
        $this->message = $message;
        $this->action = $action;
    }

    public function call() {
        if ($this->action instanceof Menu) {
            return $this->action->show();
        } else if (is_callable($this->action)) {
            call_user_func($this->action);
        } else {
            throw new \Exception('Invalid action!');
        }
    }
}
