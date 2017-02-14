<?php defined('BASEPATH') OR exit('No direct script access allowed');

// SDK for create bot
use \LINE\LINEBot;
use \LINE\LINEBot\HTTPClient\CurlHTTPClient;

// SDK for build message
use \LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use \LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;

// SDK for build button and template action
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use \LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends CI_Controller {

  private $bot;
  private $events;
  private $signature;
  private $user;

  function __construct()
  {
    parent::__construct();
    $this->load->model('tebakkode_m');
    // create bot object
    $httpClient = new CurlHTTPClient($_ENV['CHANNEL_ACCESS_TOKEN']);
    $this->bot  = new LINEBot($httpClient, ['channelSecret' => $_ENV['CHANNEL_SECRET']]);
  }

  public function index()
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo "Hello Coders!";
      header('HTTP/1.1 400 Only POST method allowed');
      exit;
    }

    // get request
    $body = file_get_contents('php://input');
    $this->signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : "-";
    $this->events = json_decode($body, true);

    // log every event requests);
    $this->tebakkode_m->log_events($this->signature, $body);

    foreach ($this->events['events'] as $event) {

       // skip group and room event
       if(! isset($event['source']['userId'])) continue;

       // get user data from database
       $this->user = $this->tebakkode_m->getUser($event['source']['userId']);

       // respond event
       if($event['type'] == 'message'){
           if(method_exists($this, $event['message']['type'].'Message')){
           $this->{$event['message']['type'].'Message'}($event);
           }
       }
       else {
           if(method_exists($this, $event['type'].'Callback')){
               $this->{$event['type'].'Callback'}($event);
           }
       }
    }
  }

  private function followCallback($event) {
      $res = $this->bot->getProfile($event['source']['userId']);
      if ($res->isSucceeded()) {
        $profile = $res->getJSONDecodedBody();
        // save user data
        $this->tebakkode_m->saveUser($profile);

        // send welcome message
        $message = "Salam kenal, " . $profile['displayName'] . "!\n";
        $message .= "Silakan kirim pesan \"MULAI\" untuk memulai kuis.";
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->pushMessage($event['source']['userId'], $textMessageBuilder);
        $stickerMessageBuilder = new StickerMessageBuilder(1, 13);
        $this->bot->pushMessage($event['source']['userId'], $stickerMessageBuilder);
    }
  }

  private function textMessage($event) {
        $userMessage = $event['message']['text'];

        if($this->user['number'] == 0) {
            if(strtolower($userMessage) == 'mulai') {
                // reset score
                $this->tebakkode_m->setScore($this->user['user_id'], 0);

                // update number progress
                $this->tebakkode_m->setUserProgress($this->user['user_id'], 1);

                // send question no.1
                $this->sendQuestion($this->user['user_id'], 1);
            } else {
                $message = 'Silakan kirim pesan "MULAI" untuk memulai kuis.';
                $textMessageBuilder = new TextMessageBuilder($message);
                $this->bot->pushMessage($event['source']['userId'], $textMessageBuilder);
            }

        // if user already begin test
        } else {
            $this->checkAnswer($userMessage);
        }
    }

    public function sendQuestion($user_id, $questionNum = 1)
    {

        // get question from database
        $question = $this->tebakkode_m->getQuestion($questionNum);

        // prepare answer options
        for($opsi = "a"; $opsi <= "d"; $opsi++) {
            if(!empty($question['option_'.$opsi]))
                $options[] = new MessageTemplateActionBuilder($question['option_'.$opsi], $question['option_'.$opsi]);
        }

        // prepare button template
        $buttonTemplate = new ButtonTemplateBuilder($question['number']."/10", $question['text'], $question['image'], $options);

        // build message
        $messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat soal", $buttonTemplate);

        // send message
        $response = $this->bot->pushMessage($user_id, $messageBuilder);
    }

    private function checkAnswer($message)
    {

        // if answer is true, increment score
        if($this->tebakkode_m->isAnswerEqual($this->user['number'], $message)){
            $this->user['score']++;
            $this->tebakkode_m->setScore($this->user['user_id'], $this->user['score']);
        }

        if($this->user['number'] < 10)
        {
            // update number progress
            $this->tebakkode_m->setUserProgress($this->user['user_id'], $this->user['number'] + 1);

            // send next number
            $this->sendQuestion($this->user['user_id'], $this->user['number'] + 1);
        }

        else {
            // show user score
            $message = 'Skormu '. $this->user['score'];
            $textMessageBuilder = new TextMessageBuilder($message);
            $this->bot->pushMessage($this->user['user_id'], $textMessageBuilder);

            $textMessageBuilder = new TextMessageBuilder($message);
            $this->bot->pushMessage($this->user['user_id'], $textMessageBuilder);

            $this->tebakkode_m->setUserProgress($this->user['user_id'], 0);
        }
    }
}
