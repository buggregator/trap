<?php

declare(strict_types=1);

namespace Buggregator\Client\Command;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run application
 */
final class Test extends Command
{
    protected static $defaultName = 'test';

    public function configure()
    {
        // $this->addArgument('type', null, 'Type of message', null);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->dump();
        // \usleep(100_000);
        // $this->mail();

        return Command::SUCCESS;
    }

    private function dump(): void
    {
        $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
        $_SERVER['VAR_DUMPER_SERVER'] = '127.0.0.1:9912';

        \dump(['foo' => 'bar']);
    }

    private function mail(): void
    {
        //Create an instance; passing `true` enables exceptions
        $mail = new PHPMailer(true);

        try {
            //Server settings
            $mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;                      //Enable verbose debug output
            $mail->isSMTP();                                            //Send using SMTP
            $mail->Host       = '127.0.0.1:9912';                       //Set the SMTP server to send through
            $mail->SMTPAuth   = false;                                   //Enable SMTP authentication
            // $mail->Username   = 'user@example.com';                     //SMTP username
            // $mail->Password   = 'secret';                               //SMTP password
            // $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            //Enable implicit TLS encryption
            // $mail->Port       = 9912;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            //Recipients
            $mail->setFrom('from@example.com', 'Mailer');
            $mail->addAddress('joe@example.net', 'Joe User');     //Add a recipient
            $mail->addAddress('ellen@example.com');               //Name is optional
            $mail->addReplyTo('info@example.com', 'Information');
            $mail->addCC('cc@example.com');
            $mail->addBCC('bcc@example.com');

            //Attachments
            // $mail->addAttachment('/var/tmp/file.tar.gz');         //Add attachments
            // $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name

            //Content
            $mail->isHTML(false);                                  //Set email format to HTML
            $mail->Subject = 'Here is the subject';
            $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
            $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

            $mail->send();
            // Write  line about success

        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
