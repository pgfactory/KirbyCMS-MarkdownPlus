<?php

namespace PgFactory\MarkdownPlus;

use Kirby\Data\Data;
use PgFactory\PageFactory\PageFactory;
use function PgFactory\PageFactory\explodeTrim;

const MDP_LOG_PATH = 'site/logs/';


/**
 * Evaluates a $permissionQuery against the current visitor's status.
 * Options:
 *  $permissionQuery = true         -> synonym for 'loggedin|loggedin'
 *  $permissionQuery = loggedin     -> permitted if a visitor is logged in, no matter what role
 *  $permissionQuery = anybody      -> always permitted, no matter whether logged in or not
 *  $permissionQuery = anon         -> permitted if NOT logged in
 *  $permissionQuery = 'user=xy'    -> permitted if username is 'xy'
 *  $permissionQuery = 'role=xy'    -> permitted if user's role is 'xy'
 *  $permissionQuery = 'xy'         -> permitted if role or username or user's email is 'xy'
 *  $permissionQuery = 'localhost'  -> permitted if browser running on local host
 *  $permissionQuery = 'xy|localhost'-> combined with other criteria
 */
class Permission
{
    private static array $anonAccess = [];

    /**
     * Evaluates a $permissionQuery against the current visitor's status.
     * @param string $permissionQuery
     * @return bool
     */
    public static function evaluate(string $permissionQuery, bool $allowOnLocalhost = true): bool
    {
        if (!$permissionQuery) {
            return false;
        }

        $permissionQueryStr = strtolower($permissionQuery);

        // handle special option 'localhost' -> take session var into account:
        session_start();
        if (($_SESSION['pfy.debug']??null) === false) { // debug explicitly false
            $allowOnLocalhost = false;
        }
        session_abort();
        if (str_contains($permissionQuery, 'localhost')) {
            if (self::isLocalhost() && $allowOnLocalhost) {
                return true;
            }
        }

        $user = self::checkPageAccessCode();

        $name = $role = $email = false;
        if (is_object($user)) {
            $name = strtolower($credentials['name']??'');
            $email = strtolower($credentials['email']??'');
            $role = strtolower($user->role()->name());
        }
        $loggedIn = (bool)$user;
        $admission = false;

        $queries = explodeTrim('|', $permissionQueryStr);
        foreach ($queries as $permissionQuery) {
            // special case 'nobody' or 'noone' -> deny in any case:
            if ($permissionQuery === 'nobody' || $permissionQuery === 'noone') {
                return false;

                // special case 'anybody' or 'anyone' -> always grant access:
            } elseif ($permissionQuery === 'anybody' || $permissionQuery === 'anyone') {
                return true;
            }

            if ($permissionQuery === 'notloggedin' || $permissionQuery === 'anon,') {
                $admission |= !$loggedIn;

            } elseif ($permissionQuery === 'loggedin') {
                $admission |= $loggedIn;

            } elseif (preg_match('/^user=(\w+)/', $permissionQuery, $m)) {
                if (($name === $m[1]) || ($m[1] === 'loggedin')) { // explicit user
                    $admission |= $loggedIn;
                } elseif ($m[1] === 'anon') { // special case: user 'anon'
                    $admission |= !$loggedIn;
                }

            } elseif (preg_match('/^role=(\w+)/', $permissionQuery, $m)) {
                if ($role === $m[1]) { // explicit role
                    $admission |= $loggedIn;
                }

            } elseif (($name === $permissionQuery) || ($email === $permissionQuery) || ($role === $permissionQuery)) { // implicit
                $admission |= $loggedIn;
            }
        }
        return (bool)$admission;
    } // evaluate


    /**
     * AccessCodes are submitted as ?a=ABCDEFGH.
     * Valid AccessCodes are defined in either config.php or page's meta-files.
     * @return bool
     * @throws \Exception
     */
    public static function checkPageAccessCode(): mixed
    {
        $session = kirby()->session();
        $page = page()->id();

        // check whether there is an access code in url-args:
        if (!isset($_GET['a'])) {
            // check whether already granted:
            if ($user = $session->get('pfy.accessCodeUser')) {
                return $user;
            }

            if (self::$anonAccess[$page]??false) {
                return 'anon';
            }
            return kirby()->user(); // no access request, return regular login status

        } else {
            // get access code:
            $submittedAccessCode = get('a', null);
            unset($_GET['a']);
        }

        // try to get access codes from meta-file "accessCode:" resp. "accessCodes:" field:
        $pageAccessCodes = page()->accesscodes()->value();
        if (!$pageAccessCodes) {
            $pageAccessCodes = page()->accesscode()->value();
            if (!$pageAccessCodes) {
                // if nothing found, try to get from $config file:
                $options = kirby()->option('pgfactory.markdownplus.options');
                if (!($pageAccessCodes = ($options['accessCodes']??false))) {
                    if (!($pageAccessCodes = ($options['accessCode']??false))) {
                        return false;
                    }
                }
            }
        }

        // convert if necessary:
        if (is_string($pageAccessCodes)) {
            $pageAccessCodes = Data::decode($pageAccessCodes, 'YAML');
        }
        if (!is_array($pageAccessCodes)) {
            // no valid code definitions found:
            $pageAccessCodes = json_encode($pageAccessCodes);
            if (PageFactory::$debug??false) {
                throw new \Exception("Invalid AccessCode found for page '$page': '$pageAccessCodes'");
            } else {
                self::mylog("Invalid AccessCode found for page '$page': '$pageAccessCodes'", 'login-log.txt');
                return false;
            }
        }

        // check order, flip array if necessary:
        //   -> case of reversed AccessCodes:
        //           ABCDEFGH: a_member@domain.net
        $val1 = array_values($pageAccessCodes)[0];
        if (\Kirby\Toolkit\V::email($val1)) {
            $pageAccessCodes = array_flip($pageAccessCodes);
        }

        // check whether given code has been defined:
        $found = array_search($submittedAccessCode, $pageAccessCodes);
        if ($found !== false) {
            if ($email = \Kirby\Toolkit\V::email($found) ? $found : false) {
                self::impersonateUser($email);
                $user = kirby()->user($email);
                if ($user) {
                    $session->set('pfy.message', 'You are logged in now');
                    $session->set('pfy.accessCodeUser', $user);
                    self::mylog("AccessCode '$submittedAccessCode' validated and user logged-in as '$email' on page '$page'", 'login-log.txt');
                }
            } else {
                $user = 'anon';
                self::$anonAccess[$page] = true;
                self::mylog("AccessCode '$submittedAccessCode' validated on page '$page'", 'login-log.txt');
            }
            // grant access:
            return $user;

        } else {
            // deny access, log unsucessfull access attempt:
            $session->remove('pfy.accessCodeUser');
            self::mylog("AccessCode '$submittedAccessCode' rejected on page '$page'", 'login-log.txt');
        }
        return kirby()->user(); // no access granted, resp. fall back to originally logged in user
    } // checkPageAccessCode


    /**
     * @param string $userQuery
     * @return string|bool
     * @throws \Throwable
     */
    private static function impersonateUser(string $userQuery): string|bool
    {
        if (str_contains($userQuery, '@')) {
            $email = $userQuery;
        } else {
            $email = false;
            $user = self::findUser($userQuery);
            if (is_object($user)) {
                $email = strtolower($user->credentials()['email'] ?? '');
            }
        }
        if ($email) {
            if ($user = kirby()->impersonate($email)) {
                $email = strtolower($user->credentials()['email'] ?? '');
                return $email;
            } else {
                return true;
            }
        } else {
            return true;
        }
    } // impersonateUser


    /**
     * @param string $value
     * @return object|bool
     */
    private static function findUser(string $value): object|bool
    {
        $users = kirby()->users();
        if ($user = $users->findBy('name', $value)) {
            return $user;
        }
        if ($user = $users->findBy('email', $value)) {
            return $user;
        }
        return false;
    } // renderUserList


    /**
     * @return bool
     */
    private static function isLocalhost(): bool
    {
        // url-arg ?localhost=false let's you mimick a remote host:
        if (($_GET['localhost']??'') === 'false') {
            return false;
        }
        $ip = kirby()->visitor()->ip();
        return ((str_starts_with($ip, '192.')) || ($ip === '::1'));
    } // isLocalhost


    /**
     * @param string $str
     * @param mixed $filename
     * @return void
     * @throws \Exception
     */
    private static function mylog(string $str, mixed $filename = false): void
    {
        $filename = $filename?: 'log.txt';

        if (!\Kirby\Toolkit\V::filename($filename)) {
            return;
        }
        if (!file_exists(MDP_LOG_PATH)) {
            mkdir(MDP_LOG_PATH, recursive: true);
        }
        $logFile = MDP_LOG_PATH. $filename;

        $str = date('Y-m-d H:i:s')."  $str\n\n";
        if (file_put_contents($logFile, $str, FILE_APPEND) === false) {
            throw new \Exception("Writing to file '$logFile' failed");
        }
    } // mylog

} // Permission

