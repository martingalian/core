<?php

namespace Martingalian\Core\Exceptions;

/**
 * Type of exception that will work as a normal runtime exception
 * but on the BaseQueableJob it will not notify the admins under
 * the reportAndFail() method.
 */
class NonNotifiableException extends \Exception
{
}
