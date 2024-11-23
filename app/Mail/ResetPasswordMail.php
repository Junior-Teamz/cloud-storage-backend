<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private $name;
    private $resetLink;

    /**
     * Create a new message instance.
     *
     * @param string $resetLink
     */
    public function __construct($name, $resetLink)
    {
        $this->name = $name;
        $this->resetLink = $resetLink;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $imagePath = 'application_image/KemenkopUKM File Sharing Logo.png';
        $imagePath2 = Storage::path($imagePath);
        $imageStorage = Storage::disk('public')->get($imagePath);

        return $this->subject('Reset Password Request')
            ->view('emails.reset-password')
            ->with([
                'name' => $this->name,
                'resetLink' => $this->resetLink,
            ])
            ->withSwiftMessage(function ($message) use ($imagePath2) {
                $cid = $message->embed($imagePath2);
                $this->with('imageUrl', $cid);
            });
    }
}
