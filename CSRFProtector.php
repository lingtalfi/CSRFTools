<?php

namespace Ling\CSRFTools;


/**
 * The CSRFProtector class.
 *
 * This class is a singleton.
 *
 *
 * How this class works
 * ================
 * Before using this class, you should have a basic understanding of how it works.
 *
 * In this class, a token is composed of two things:
 *
 * - a name
 * - a value
 *
 * The value of the token is always generated by this class, it's a random value, like 98f0z0fzegijzgozhu for instance.
 * The name of the token is chosen by you.
 *
 * With this class, you can generate tokens, using the createToken method.
 * This method will not only return the value of the token, but also store it in the php session.
 *
 * You can then check whether a given token has the value you think it has (using the isValid method): this is the
 * heart of the CSRF validation; if you can predict the value of a token, then you must be the one who created it (at least
 * that's the logic behind it).
 *
 * Now what's peculiar to this class is that the tokens are stored in slots.
 * More precisely, there are two possible slots:
 * - new
 * - old
 *
 * By default, the createToken method stores a token in the new slot.
 * But then if you call the createToken method again with the same token name, the "new" slot will be replaced by
 * a new token value (random string generated by this class), but the old token value is transferred to the
 * "old" slot rather than being completely replaced.
 *
 * Why is that so you might ask?
 *
 * This has to do with how forms are implemented in php applications.
 * A form is first displayed, then the user posts the form.
 * In terms of page invocation, this means that the form page is usually invoked at least twice:
 * - the first time to display the form to the user
 * - the second time to test the posted data against some validation mechanism
 *
 * Usually, to protect a form against CSRF, the developer will create a page which calls the two main methods of this class:
 * - createToken
 * - isValid
 *
 * Now in which order those methods will be called depends on the coding style of the developer, it could be:
 *
 * - createToken
 * - isValid
 *
 * or
 *
 * - isValid
 * - createToken
 *
 * In the first case, where createToken is called before isValid, because the page is called twice,
 * we can see that the first time the page is called (when the form is just displayed), the token is created, and the isValid method
 * is probably not relevant at this stage (i.e. without posted data).
 * Now when the user posts the page via http post, the page is reloaded and the createToken method is called again BEFORE the isValid method
 * is called, which means the token is different than the one the user posted.
 *
 * Now that's exactly the reason why this class uses the "old" slot: because in this precise case you need to validate the posted CSRF token
 * against the old token value (created during the first invocation of the page, when the form was just displayed), not against the new value.
 *
 * Hopefully this gives you an insight about why there are two slots.
 * Now which slot you want to validate against really depends on your application and your concrete case.
 *
 * For a simple validation between two separate pages, for instance a page A.php that creates the token, and an ajax B.php page that
 * calls the isValid method, then the B.php page needs to validate against the new value, since the createToken method has only been called once.
 *
 * In the case of the form with the isValid method being called first, since the creation of the token is the last (relevant to this
 * discussion) thing the page does, then we can validate against the new slot.
 *
 * So, validating against the new or the old slot is the main question you should ask yourself before using this class.
 * This requires an understanding of how your application is wired.
 * Once you know how your application works, the solution should be quite obvious.
 *
 *
 *
 * The delete method
 * -------------
 * Why is there a deleteToken method?
 *
 * Imagine you have a simple ajax communication you want to secure.
 * A.php is the script which creates the token (createToken is called).
 * B.php is the ajax script which validates/un-validates the token (isValid is called).
 *
 * Imagine a legit user does its thing, and A.php is invoked, which in turns calls B.php.
 * The user gets its action done, and everything is fine.
 * Except that now the token is still in the user session.
 *
 * Which means there is still a small chance that a malicious user could impersonate the user:
 * if the malicious user can make the gentle user to click a link to B.php with the right token (I know, that sounds
 * almost impossible, but just imagine), then assuming the user session is still active, the action would technically
 * be re-executed.
 *
 * Now in practise, although I'm not a security expert, I believe it's almost impossible for a malicious user
 * to guess the random token, and so this extra precaution is maybe too much, but for those of you who are paranoid,
 * if you want to do everything you can to make it harder for the malicious users, then by destroying the token
 * after having it validated by the regular user, you remove this tiny risk.
 *
 * So basically, the algo in B.php would look like this:
 *
 * - if $csrfProtector->isValid
 * -    $csrfProtector->deleteToken
 * -    // do the secure action
 *
 * And because the token won't exist in the session anymore, even if the malicious user managed to know the right token,
 * the token would be stale (or more precisely it wouldn't exist anymore), and so the secure action will not be executed again.
 *
 * Now it's not always possible (depending on your design) to call the deleteToken method, but on ajax calls it's certainly always possible.
 * In fact with forms it might be complicated some time, because you might delete a token that needs to be there, your mileage might vary...
 *
 *
 *
 *
 *
 *
 *
 */
class CSRFProtector
{

    /**
     * This property holds its own instance.
     * @var CSRFProtector
     */
    private static $inst = null;


    /**
     * This property holds the sessionName for this instance.
     *
     * It's like a namespace containing all tokens generated by this class.
     *
     * You shouldn't change this, as it's unlikely that you would have a session variable named csrf_tools_token.
     * But if you were, you could extend this class and change that sessionName.
     *
     *
     *
     * @var string
     */
    protected $sessionName;


    /**
     * Gets the singleton instance for this class.
     *
     *
     * @return CSRFProtector
     */
    public static function inst()
    {
        if (null === self::$inst) {
            self::$inst = new static();
        }
        return self::$inst;
    }


    /**
     * Builds the CSRFProtector instance.
     * Notice that it's private.
     */
    private function __construct()
    {

        $this->sessionName = "csrf_tools_token";
        $this->startSession();
    }


    /**
     * Creates the token named $tokenName, stores its value in the "new" slot, and returns the token value.
     * If the token named $tokenName already exists, there is a rotation: the newly created token is stored in the "new" slot,
     * while the old "new" value (found in the "new" slot before it was replaced) is moved to the "old" slot.
     *
     * For more details, please refer to this class description.
     *
     *
     * @param string $tokenName
     * @return string
     */
    public function createToken(string $tokenName): string
    {

        if (array_key_exists($tokenName, $_SESSION[$this->sessionName])) {
            $_SESSION[$this->sessionName][$tokenName]['old'] = $_SESSION[$this->sessionName][$tokenName]['new'];
        }

        $token = md5(uniqid());
        $_SESSION[$this->sessionName][$tokenName]['new'] = $token;
        return $token;
    }


    /**
     * Returns whether the given $tokenName exists and has the given $tokenValue.
     *
     *
     * @param string $tokenName
     * @param string $tokenValue
     * @param bool=false $useNewSlot
     * @return bool
     */
    public function isValid(string $tokenName, string $tokenValue, bool $useNewSlot = false): bool
    {

        if (array_key_exists($tokenName, $_SESSION[$this->sessionName])) {
            if (false === $useNewSlot) {
                if (array_key_exists("old", $_SESSION[$this->sessionName][$tokenName])) {
                    $res = ($tokenValue === $_SESSION[$this->sessionName][$tokenName]["old"]);
                } else {
                    $res = false;
                }
            } else {
                $res = ($tokenValue === $_SESSION[$this->sessionName][$tokenName]["new"]);
            }
            return $res;

        }
        return false;
    }


    /**
     * Deletes the given $tokenName.
     *
     * @param string $tokenName
     */
    public function deleteToken(string $tokenName)
    {
        unset($_SESSION[$this->sessionName][$tokenName]);
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Ensures that the php session has started.
     */
    protected function startSession()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        if (false === array_key_exists($this->sessionName, $_SESSION)) {
            $_SESSION[$this->sessionName] = [];
        }
    }
}