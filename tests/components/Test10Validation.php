<?php
namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test10Validation extends Component
{
    public string $email = '';
    public string $error = '';
    public string $success = '';

    public function subscribe(): void
    {
        $inputEmail = $this->wire('sanitizer')->email($this->wire('input')->post('email', 'text'));

        $this->error = '';
        $this->success = '';

        if (!$inputEmail) {
            $this->error = "Please provide a valid email address.";
            // We do not save an invalid email to state
        } else {
            $this->email = $inputEmail;
            $this->success = "Successfully subscribed {$this->email}!";
        }
    }

    public function render(): string
    {
        $alertHtml = '';
        if ($this->error) {
            $alertHtml = "<div class='uk-alert uk-alert-danger'><p>" . htmlspecialchars($this->error, ENT_QUOTES) . "</p></div>";
        } elseif ($this->success) {
            $alertHtml = "<div class='uk-alert uk-alert-success'><p>" . htmlspecialchars($this->success, ENT_QUOTES) . "</p></div>";
        }

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test10'>
            <h3 class='uk-card-title'>Test 10: Validation State</h3>
            {$alertHtml}
            <form hx-post='{$this->requestUrl()}' hx-target='#test10' hx-swap='outerHTML' hx-select='#test10'>
                {$this->renderStatePayload()}
                
                <div class='uk-margin'>
                    <input class='uk-input" . ($this->error ? " uk-form-danger" : "") . "' 
                           type='email' 
                           name='email' 
                           placeholder='Enter Email'
                           value='" . htmlspecialchars($this->email, ENT_QUOTES) . "'>
                </div>

                <button type='submit' name='hx__action' value='subscribe' class='uk-button uk-button-primary'>Subscribe</button>
            </form>
        </div>";
    }
}
