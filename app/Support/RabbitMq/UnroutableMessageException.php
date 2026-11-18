<?php

namespace App\Support\RabbitMq;

/** The broker accepted the message but no queue is bound to its routing key (it came back "returned"). */
class UnroutableMessageException extends \RuntimeException {}
