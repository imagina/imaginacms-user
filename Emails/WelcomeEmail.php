<?php

namespace Modules\User\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\User\Entities\UserInterface;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var UserInterface
     */
    public $user;

    public $activationCode;

    public function __construct(UserInterface $user, $activationCode)
    {
        $this->user = $user;
        $this->activationCode = $activationCode;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('user::emails.welcome')
            ->subject(trans('user::messages.welcome'));
    }
}
