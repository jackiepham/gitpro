<?php
class Controller {
	/**
	 * Extra parameters from the end of the URL.
	 */
	public $params = array ();

	/**
	 * Whether the request originated internally or externally.
	 */
	public $internal = true;

	/**
	 * Data sent from another handler to the current one.
	 */
	public $data = array ();

	/**
	 * Set to true if the request came from the command line.
	 */
	public $cli = false;

	/**
	 * A list of handlers defined to be called for each type of hook.
	 * Similar to the idea of webhooks, this provides a means of triggering
	 * handlers from each other without hard-coding the specific handlers in
	 * the triggering code. See `conf/config.php`'s `[Hooks]` section for examples.
	 */
	public static $hooks = array ();

	/**
	 * Keeps track of the number of times each handler has been called in
	 * this request. You can check `self::$called['handler']` for the
	 * number.
	 */
	public static $called = array ();

	/**
	 * Cached PUT data from get_put_data() so it only reads it the first
	 * time.
	 */
	public $put_data = null;

	/**
	 * The app that is being called. Set by `route()`.
	 */
	public $app;

	/**
	 * The uri that was last called, as parsed by `route()`. This will have
	 * preceding slashes trimmed, and if it resolves to a default like
	 * `app -> app/index`, then it will have the `/index` added.
	 */
	public $uri;

	/**
	 * When a handler is loaded, if there is a `conf/config.php` for that
	 * app, its contents will be loaded into `$appconf['appname']` once
	 * the first time it is called, and accessible thereafter by any
	 * handler in that app directly via $appconf.
	 */
	public static $appconf = array ();

	/**
	 * This will be set the first time chunked() is called, so the controller
	 * knows it's already started sending the response with
	 * `Transfer-Encoding: chunked`.
	 */
	public $chunked = false;

	/**
	 * Whether the current handler's output should be cached automatically
	 * when it returns. Set to `true` to cache indefinitely, and a number
	 * to set a timeout in seconds. The cache key will be the app and handler
	 * name, with slashes converted to underscores, e.g., `myapp_handler`.
	 *
	 * Usage:
	 *
	 *     // cache indefinitely
	 *     $this->cache = true;
	 *
	 *     // cache for 5 minutes
	 *     $this->cache = 300;
	 *
	 * To clear a cached handler before its time, which you would have to
	 * do from a separate handler since the original won't be called while
	 * cached, you can use:
	 *
	 *     $memcache->delete ('myapp_handler');
	 */
	public $cache = false;

	/**
	 * Constructor method.
	 */
	public function __construct ($hooks = array ()) {
		if (defined ('STDIN')) {
			$this->cli = true;
		}
		self::$hooks = $hooks;
	}

	/**
	 * Run an internal request from one handler to another.
	 */
	public function run ($uri, $data = array ()) {
		$c = new Controller (conf ('Hooks'));
		$handler = $c->route ($uri);

		if (! isset (self::$called[$uri])) {
			self::$called[$uri] = 1;
		} else {
			self::$called[$uri]++;
		}

		return $c->handle ($handler, true, $data);
	}

	/**
	 * Trigger the default error handler. Note that you must echo the
	 * output from your handler before returning, for example:
	 *
	 *     echo $this->error ();
	 *     return;
	 *
	 * Not like this:
	 *
	 *     return $this->error ();
	 */
	public function error ($code = 404, $title = 'Page not found', $message = '') {
		// Erase any existing output up to this point
		ob_clean ();

		// Call the error handler
		return $this->run (conf ('General', 'error_handler'), array (
			'code' => $code,
			'title' => $title,
			'message' => $message
		));
	}

	/**
	 * Run any handlers for the specified hook type. Note that the
	 * output for hooks is ignored.
	 */
	public function hook ($type, $data = array ()) {
		if (! isset (self::$hooks[$type])) {
			return false;
		}
		foreach (self::$hooks[$type] as $handler) {
			$this->run ($handler, $data);
		}
	}

	/**
	 * Takes a list of names as arguments and returns an associative
	 * array of the handler parameters using the names as keys. Note
	 * that it will return false if the number of names is different
	 * than the number of parameters. Handy for using named parameters
	 * via:
	 *
	 *     extract ($this->params ('id', 'title'));
	 *
	 * Note that you can also achieve the same thing via:
	 *
	 *     list ($id, $title) = $this->params;
	 */
	public function params () {
		$keys = func_get_args ();
		return array_combine ($keys, $this->params);
	}

	/**
	 * Execute the request handler. $internal determines whether the
	 * request originated internally from another handler or template,
	 * or externally from a browser request.
	 *
	 * Note: We use three globals here. This may raise some flags in
	 * you as a developer, but hear me out. These are global singletons
	 * that the front controller creates for us, and I want to be able
	 * to use them in handlers directly without first instantiating them,
	 * through a `::getInstance()` call or otherwise. I know it's bad
	 * form in general, but this is *by design* to save typing in handlers
	 * and happens for these three objects only.
	 *
	 * I also could have simply added them as properties of `$this`, but
	 * that would add typing too (e.g., `$this->view->render` vs
	 * `$tpl->render`). I'm opting for conciseness. And as it is, I'm
	 * deliberately making an ordinary script act like a controller, minus
	 * the class wrapping it. It's a stylistic decision, and if it's not
	 * your cup of tea, that's cool. It is mine, however :)
	 */
	public function handle ($handler, $internal = true, $data = array ()) {
		global $page, $tpl, $memcache;

		// Check for a cached copy of this handler's output
		$out = $memcache->get (str_replace ('/', '_', $this->uri));
		if ($out) {
			return $out;
		}

		// Set the handler data
		$this->internal = $internal;
		$data = (array) $data;
		$this->data = $data;

		// Load the app's configuration settings if available
		if (! isset (self::$appconf[$this->app])) {
			try {
				self::$appconf[$this->app] = @file_exists ('apps/' . $this->app . '/conf/config.php')
				? parse_ini_file ('apps/' . $this->app . '/conf/config.php', true)
				: array ();
			} catch (Exception $e) {
				self::$appconf[$this->app] = array ();
			}
		}
		$appconf = self::$appconf[$this->app];

		// Run the handler and get its output
		ob_start ();
		require ($handler);
		$out = ob_get_clean ();

		// If this is a chunked request, flush and exit
		if ($this->chunked) {
			$this->flush ($out);
			$this->flush (null);
		}

		// If the handler is cacheable, cache the results before returning
		if ($this->cache !== false) {
			$timeout = is_numeric ($this->cache) ? $this->cache : 0;
			$res = $memcache->replace (str_replace ('/', '_', $this->uri), $out, 0, $timeout);
			if ($res === false) {
				$memcache->set (str_replace ('/', '_', $this->uri), $out, 0, $timeout);
			}
		}
		return $out;
	}

	/**
	 * Route a request URI to a file.
	 */
	public function route ($uri) {
		$exp = explode ('/', conf ('General', 'default_handler'));
		$this->app = array_shift ($exp);
		$this->params = array ();

		// Remove queries and hash from uri
		$uri = preg_replace ('/(\?|#).*$/', '', $uri);

		if (! $this->clean ($uri) || $uri === '/') {
			$uri = conf ('General', 'default_handler');
		}

		// Remove leading /
		$uri = ltrim ($uri, '/');

		// If no / and doesn't match an app's name with an index.php
		// handler, then use the default handler.
		if (! strstr ($uri, '/')) {
			if (@file_exists ('apps/' . $uri . '/handlers/index.php')) {
				$uri .= '/index';
			} else {
				$this->add_param ($uri);
				$uri = conf ('General', 'default_handler');
			}
		}

		// Determine the handler by cascading through potential file names
		// until one matches.
		list ($app, $handler) = explode ('/', $uri, 2);
		$route = 'apps/' . $app . '/handlers/' . $handler . '.php';
		while (! @file_exists ($route)) {
			$route = preg_replace ('/\/([^\/]*)\.php$/e', '$this->add_param (\'\\1\')', $route);
			if ($route === 'apps/' . $app . '/handlers.php') {
				if (@file_exists ('apps/' . $app . '/handlers/index.php')) {
					$this->app = $app;
					$this->uri = $app . '/index';
					return 'apps/' . $app . '/handlers/index.php';
				}
				$this->app = $app;
				$this->uri = conf ('General', 'default_handler');
				return vsprintf (
					'apps/%s/handlers/%s.php',
				explode ('/', conf ('General', 'default_handler'))
				);
			}
		}
		$this->app = $app;
		$this->uri = $uri;
		return $route;
	}

	/**
	 * Is this URL clean of any directory manipulation attempts?
	 */
	public function clean ($url) {
		return ! strstr ($url, '..');
	}

	/**
	 * Adds to the start, since `route()` parse them off the end of the URI.
	 */
	public function add_param ($param) {
		array_unshift ($this->params, $param);
		return '.php';
	}

	/**
	 * Turn a relative URL into an absolute URL. If the `$base` is false,
	 * it will use the HTTP_HOST to construct a base for you.
	 */
	public function absolutize ($url, $base = false) {
		if (strpos ($url, '://') !== false) {
			// already contains scheme
			return $url;
		}

		if (strpos ($url, '//') === 0) {
			// scheme relative, add scheme
			return $this->is_https ()
			? 'https:' . $url
			: 'http:' . $url;
		}

		if ($base === false) {
			// construct the base from HTTP_HOST
			$base = $this->is_https ()
			? 'https://' . $_SERVER['HTTP_HOST']
			: 'http://' . $_SERVER['HTTP_HOST'];
		}

		if (strpos ($url, '/') === 0) {
			// absolute to the site root
			return $base . $url;
		}

		// relative, so assume the base contains enough of
		// a path prefix
		return (substr ($base, -1) === '/')
		? $base . $url
		: $base . '/' . $url;
	}

	/**
	 * Redirect the current request and exit.
	 */
	public function redirect ($url, $exit = true) {
		header ('Location: ' . $this->absolutize ($url));
		if ($exit) {
			$this->quit ();
		}
	}

	/**
	 * Wrapper around exit to work with subfolder installations.
	 */
	public function quit () {
		exit;
	}

	/**
	 * Get the stdin stream for PUT requests.
	 */
	public function get_put_data () {
		if ($this->put_data === null) {
			$stdin = fopen ('php://input', 'r');
			$out = '';
			while ($data = fread ($stdin, 1024)) {
				$out .= $data;
			}
			fclose ($stdin);
			$this->put_data = $out;
		}
		return $this->put_data;
	}

	/**
	 * Get the raw POST data.
	 */
	public function get_raw_post_data () {
		return $GLOBALS['HTTP_RAW_POST_DATA'];
	}

	/**
	 * Get the request method. If X-HTTP-Method-Override header is set,
	 * it will return that instead of the actual request method.
	 */
	public function request_method () {
		return isset ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) ? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] : $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * Use an object's methods to handle RESTful requests for the current handler.
	 */
	public function restful ($obj) {
		// Disable page layout and set JSON header.
		global $page;
		$page->layout = false;
		header ('Content-Type: application/json');

		// Verify an action has been specified.
		if (! isset ($this->params[0])) {
			return $obj->error ('No action specified');
		}

		// Method names are the request method plus the first parameter
		// after the handler name, e.g. GET /myapp/api/action_name would
		// call get_action_name().
		$method = strtolower ($this->request_method ()) . '_' . $this->params[0];

		// Verify the method exists.
		if (! method_exists ($obj, $method)) {
			return $obj->error ('Invalid action name');
		}

		// Assign the controller to the object.
		$obj->controller = $this;

		// Call the method with the extra URL parameters.
		$params = $this->params;
		array_shift ($params);
		try {
			$res = call_user_func_array (array ($obj, $method), $params);
		} catch (ErrorException $e) {
			$msg = $e->getMessage ();
			if (strpos ($msg, 'Missing argument') === 0) {
				return $obj->error ('Missing required argument');
			}
			return $obj->error ('Unexpected error occurred');
		}

		// If an error hasn't been output already, encode the response.
		if ($res !== null) {
			return $obj->wrap ($res);
		}
	}

	/**
	 * Changes the response to use `Transfer-Encoding: chunked` and sends
	 * the current buffer to the client. Call this each time you want the
	 * script to send the next chunk of data to the client.
	 *
	 * Note that this will cause render() to call `flush(null)` at the end,
	 * which will not return your output to be included in a page layout.
	 * It will also flush and exit prior to setting a controller-level
	 * cache of your output.
	 */
	function flush ($out = false) {
		if (! $this->chunked) {
			header ('Transfer-Encoding: chunked');
			$this->chunked = true;
		}
		if ($out === null) {
			// Send an empty chunk and exit
			if (ob_get_level () > 0) {
				// Flush any existing data first
				$this->flush ();
			}
			echo "0\r\n\r\n";
			flush ();
			$this->quit ();
		} elseif ($out !== false) {
			// Send the data passed to flush()
			if (strlen ($out) > 0) {
				printf ("%X\r\n", strlen ($out));
				echo $out . "\r\n";
				flush ();
			}
		} else {
			// Send the current output buffer contents
			$out = ob_get_clean ();
			if (strlen ($out) > 0) {
				printf ("%X\r\n", strlen ($out));
				echo $out . "\r\n";
				flush ();
			}
			ob_start ();
		}
	}

	/**
	 * Returns whether the current request is made over HTTPS or not.
	 */
	public function is_https () {
		if (! isset ($_SERVER['HTTPS']) || strtolower ($_SERVER['HTTPS']) !== 'on') {
			return false;
		}
		return true;
	}

	/**
	 * Forces the current request to be over HTTPS instead of HTTP
	 * via redirect if necessary.
	 */
	public function force_https () {
		if (! $this->is_https ()) {
			$this->redirect ('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		}
	}

	/**
	 * Forces the current request to be over HTTP instead of HTTPS
	 * via redirect if necessary.
	 */
	public function force_http () {
		if ($this->is_https ()) {
			$this->redirect ('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		}
	}
}