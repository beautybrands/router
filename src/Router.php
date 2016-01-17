<?php

namespace Objectiveweb;

class Router extends \Dice\Dice
{

    /**
     * Route a particular request to a callback
     *
     *
     * @throws \Exception
     * @param $request - HTTP Request Method + Request-URI Regex e.g. "GET /something/([0-9]+)/?"
     * @param $callback - A valid callback. Regex capture groups are passed as arguments to this function, using
     *   array('Namespace\ClassNameAsString', 'method') triggers the dependency injector to instantiate the given class
     * @return void or data - If the callback returns something, it's responded accordingly, otherwise, nothing happens
     */
    public function route($request, $callback)
    {
        if (is_array($callback) && is_string($callback[0])) {
            $callback[0] = $this->create($callback[0]);
        }

        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        if (!isset($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '/';
        }

        if (preg_match(sprintf("/^%s$/", str_replace('/', '\/', $request)), "{$_SERVER['REQUEST_METHOD']} {$_SERVER['PATH_INFO']}", $params)) {
            array_shift($params);

            if (func_num_args() > 2) {
                $params = array_merge($params, array_slice(func_get_args(), 2));
            }

            try {
                $response = call_user_func_array($callback, $params);
                if ($response !== NULL) {
                    static::respond($response);
                }
            } catch (\Exception $ex) {
                static::respond($ex->getMessage(), $ex->getCode());
            }
        }
    }

    /**
     * Binds a controller get/post/put/destroy or custom functions to HTTP methods
     * @param $path String path prefix (/path)
     * @param $controller mixed class name or class
     * @param ... mixed passed to controller instantiation
     * @throws \Exception
     */
    public function controller($path, $controller)
    {
        $args = func_get_args();
        array_splice($args, 0, 2);

        $this->route("([A-Z]+) $path/?(.*)", function ($method, $params) use ($controller, $args) {

            if (is_string($controller)) {
                $controller = $this->create($controller, $args);
            }

            $method = strtolower($method);

            // Process controller.before, then controller.before[Post|Get|Put|Delete|...]
            foreach (array('before', 'before' . ucfirst($method)) as $callback) {
                if (is_callable(array($controller, $callback))) {
                    call_user_func(array($controller, $callback));
                }
            }

            $params = explode("/", $params);

            switch ($method) {
                case "post":
                case "put":
                case "patch":
                    $params[] = Router::parse_post_body();
                default:
                    $params[] = $_GET;
                    break;
            }

            // Try to execute controller.[post|get|put|delete]Name() or controller.name()
            if (!empty($params[0])) {
                $_fn = str_replace('-', '_', $method . ucfirst($params[0]));
                if (is_callable(array($controller, $_fn))) {
                    array_shift($params);
                    return call_user_func_array(array($controller, $_fn), $params);
                }

                $_fn = str_replace('-', '_',$params[0]);
                if (is_callable(array($controller, $_fn))) {
                    array_shift($params);
                    array_pop($params);
                    return call_user_func_array(array($controller, $_fn), $params);
                }

            } else {
                array_shift($params);
                if ($method == 'get') $method = 'index';
            }


            if (!is_callable(array($controller, $method))) {
                throw new \Exception(sprintf(_("%s\\%s: Route not found"), get_class($controller), $method), 404);
            }

            return call_user_func_array(array($controller, $method), $params);

        });

    }

    /**
     * Matches a DELETE request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function DELETE($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("DELETE $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = $_GET;

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a GET request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function GET($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("GET $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = $_GET;

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a POST request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function POST($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("POST $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = Router::parse_post_body();

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a PUT request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function PUT($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("PUT $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = Router::parse_post_body();

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Constructs an URL for a given path
     *  - If the given url is external or exists as a file on disk, return that file's url
     *  - If the file does not exist, construct a url based on the current script + path info
     *  - If portions of the path exist, treat the rest as parameters (point to another controller)
     *
     * If the given path is NULL, returns the current url with protocol, port and so on
     *
     * Examples
     *  url('css/style.css'); returns '/some_root/my_application/css/style.css'
     *  url('1'); returns '/some_root/my_application/controller.php/1' (if we ran that command from controller.php)
     *  url('othercontroller.php/1/2'); returns '/some_root/my_application/othercontroller.php/1/2' (if othercontroller.php exists)
     *
     * @param $str
     * @return string
     */
    public static function url($str = null)
    {
        if ($str == 'self' || empty($str)) {
            if (
                isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
                || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
            ) {
                $protocol = 'https://';
            } else {
                $protocol = 'http://';
            }

            $url = $protocol . $_SERVER['HTTP_HOST'];

            // use port if non default
            $port = isset($_SERVER['HTTP_X_FORWARDED_PORT'])
                ? $_SERVER['HTTP_X_FORWARDED_PORT']
                : (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '');
            $url .=
                (($protocol === 'http://' && $port != 80) || ($protocol === 'https://' && $port != 443))
                    ? ':' . $port
                    : '';

            $url .= !empty($_SERVER['SCRIPT_URL']) ? $_SERVER['SCRIPT_URL'] : $_SERVER['PHP_SELF'];

            // return current url
            return $url;
        } else {

            if (!empty($_SERVER['PATH_INFO'])) {
                if (!empty($_SERVER['SCRIPT_URL'])) {
                    $PATH = substr($_SERVER['SCRIPT_URL'], 0, -1 * strlen($_SERVER['PATH_INFO']));
                } else {
                    $PATH = dirname($_SERVER['SCRIPT_NAME']);
                }
            } else {
                $PATH = dirname($_SERVER['SCRIPT_NAME']);
            }

            return ($PATH == '/' ? '' : $PATH) . ($str[0] == '/' ? $str : '/' . $str);

        }

    }


    public static function parse_post_body($decoded = true, $as_array = true)
    {

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                if (!empty($_POST)) {
                    return $_POST;
                }
            default:
                $post_body = file_get_contents('php://input');
                if (strlen($post_body) > 0 && $decoded) {
                    if ($post_body[0] == '{' || $post_body[0] == '[') {
                        return json_decode($post_body, $as_array);
                    } else {
                        parse_str($post_body, $return);
                        return $return;
                    }
                } else {
                    return $post_body;
                }
        }
    }

    public static function redirect($to, $code = 307)
    {
        header("HTTP/1.1 $code");
        header('Location: ' . Router::url($to));
        exit();
    }

    public static function respond($content, $code = 200)
    {

        header("HTTP/1.1 $code");

        if (is_array($content) || is_object($content)) {

            $content = json_encode($content);
        }

        if ($content[0] == '{' || $content[0] == '[') {
            header('Content-type: application/json');
        }

        exit($content);
    }

}
