<?php
declare(strict_types=1);

/**
 * One connection of a fake host agent for the CLI flow tests: appends the request line to
 * $FAKE_HOST_LOG and answers with the reply in $FAKE_HOST_REPLY (a JSON file, the whole reply
 * for every action). Run through tests/lib/socket_harness.php; never touches a real agent.
 */

$request = (string)stream_get_contents(STDIN);
file_put_contents((string)getenv('FAKE_HOST_LOG'), $request . "\n", FILE_APPEND);
echo (string)file_get_contents((string)getenv('FAKE_HOST_REPLY'));
