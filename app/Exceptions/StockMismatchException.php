<?php

namespace App\Exceptions;

use RuntimeException;

/** The stock rows do not hold what a reservation says they hold (e.g. reserved went below the reservation). */
class StockMismatchException extends RuntimeException {}
