<?php
declare(strict_types=1);

namespace App\Core\Mail;

/** A way to deliver one e-mail. */
interface Transport
{
    public function name(): string;

    /**
     * @param array{fromName?:?string,replyTo?:?string,to:string,subject:string,html:string,text:string} $mail
     * @return array{messageId?:string}
     */
    public function send(array $mail): array;
}
