<?php

require 'vendor/autoload.php';

error_reporting(E_ALL && ~E_NOTICE); //Отчёт об ошибках

use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Factory;
use React\Socket\Connector as ReactConnector;

set_exception_handler(function ($exception) {
  send_log("Error: " . $exception->getMessage());
});

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

function send_log(string $message, bool $send_to_telegram = true)
{
  $date = date('d.m.Y H:i:s');
  echo "[$date] $message" . PHP_EOL;
  if ($send_to_telegram)
    send_message([
      'text' => $message,
      'chat_id' => "1065966259"
    ]);
}

function send_message($params)
{
  $token = file_get_contents(__DIR__ . '/token.txt');
  $response = json_decode(send_curl([
    CURLOPT_URL => "https://api.telegram.org/bot$token/sendMessage",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => false,
    CURLOPT_RETURNTRANSFER => true
  ]));

  if (!$response->ok)
    send_log("Telegram ERROR: " . $response->error_code . " " . $response->description, false);
}

function on_message(string $msg)
{
  $killmail = json_decode($msg);
  $target_corps = ['98777806', '98675590'];

  try {
    $our_attackers = array_filter($killmail->attackers, fn($el) => in_array($el->corporation_id, $target_corps));
    $our_victim = in_array($killmail->victim->corporation_id, $target_corps);
  } catch (\Throwable $th) {
    send_log("Kill: " . $msg);
    return;
  }

  if ($our_victim || count($our_attackers) > 0) {
    sleep(5);
    send_message([
      'text' => ($our_victim ? "📉 " : "📈 ") . $killmail->zkb->url,
      'chat_id' => "-1002358672534",
      'message_thread_id' => 125
    ]);
  }
}

// Создаем event loop
$loop = Factory::create();

// Создаем connector для WebSocket
$reactConnector = new ReactConnector($loop);
$connector = new Connector($loop, $reactConnector);

// Адрес WebSocket-сервера
$wsServerUrl = 'wss://zkillboard.com/websocket/'; // Замените на адрес вашего сервера

// Подключаемся к серверу
$connector($wsServerUrl)
  ->then(function (WebSocket $conn) use ($loop) {
    send_log("Connected to zKillboard!", false);

    $conn->on('message', function ($msg) {
      on_message($msg);
    });

    $conn->on('error', function ($error) {
      send_log($error);
    });

    $conn->on('close', function ($code = null, $reason = null) {
      send_log("Соединение закрыто ({$code} - {$reason})");
    });

    $conn->send('{"action": "sub","channel": "killstream"}');
  }, function (\Exception $e) use ($loop) {
    echo "Ошибка подключения: {$e->getMessage()}\n";
    $loop->stop();
  });

// Запускаем event loop
$loop->run();
