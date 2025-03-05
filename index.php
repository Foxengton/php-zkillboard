<?php

require 'vendor/autoload.php';

error_reporting(E_ALL && ~E_NOTICE); //Отчёт об ошибках

use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\Socket\Connector as SocketConnector;

function send_curl(array $options)
{
  $headers = [];

  $ch = curl_init();
  curl_setopt_array($ch, $options);
  curl_setopt(
    $ch,
    CURLOPT_HEADERFUNCTION,
    function ($curl, $header) use (&$headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
        return $len;

      $headers[strtolower(trim($header[0]))][] = trim($header[1]);

      return $len;
    }
  );
  $response = curl_exec($ch);

  if ($options[CURLOPT_HEADER] == 1) {
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);

    $response = [
      'header' => $headers,
      'body' => $body,
    ];
  }

  curl_close($ch);

  return $response;
}

function send_message($params)
{
  $token = file_get_contents("token.txt");

  return send_curl([
    CURLOPT_URL => 'https://api.telegram.org/bot' . $token . '/sendMessage',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => false,
    CURLOPT_RETURNTRANSFER => true
  ]);
}

set_exception_handler(function ($exception) {
  send_message([
    'text' => $exception->getMessage(),
    'chat_id' => "1065966259"
  ]);
});

$loop = Loop::get();
$connector = new Connector($loop, new SocketConnector($loop));
$connector('wss://zkillboard.com/websocket/')->then(function (WebSocket $conn) {
  echo '[' . date('d.m.Y H:i:s') . "] Connected to zKillboard!\n";

  $conn->on('message', function ($msg) {
    $killmail = json_decode($msg);
    $target_corps = ['98777806', '98675590'];

    $our_attackers = array_filter($killmail->attackers, fn($el) => in_array($el->corporation_id, $target_corps));
    $our_victim = in_array($killmail->victim->corporation_id, $target_corps);

    if ($our_victim || count($our_attackers) > 0) {
      sleep(60);
      send_message([
        'text' => ($our_victim ? "📉 " : "📈 ") . $killmail->zkb->url,
        'chat_id' => "-1002358672534",
        'message_thread_id' => 125,
      ]);
    }
  });

  $conn->send('{"action": "sub","channel": "killstream"}');
}, function (Exception $e) use ($loop) {
  echo "Could not connect: {$e->getMessage()}\n";
  $loop->stop();
});

$loop->run();
