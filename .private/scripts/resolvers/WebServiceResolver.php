<?php
/*! WebServiceResolver.php \ IRequestResovler | Resolves service API requests. */

namespace resolvers;

use core\Log;
use core\Utility;

use framework\Request;
use framework\Response;
use framework\Service;
use framework\System;

use framework\exceptions\ResolverException;

class WebServiceResolver implements \framework\interfaces\IRequestResolver {

  /**
   * @constructor
   */
  public function __construct($options) {
    if ( empty($options['prefix']) ) {
      throw new ResolverException('Please provide a proper path prefix.');
    }
    else {
      // Routes must start from root
      $this->pathPrefix($options['prefix']);
    }
  }

  //--------------------------------------------------
  //
  //  Properties
  //
  //--------------------------------------------------

  //------------------------------
  //  pathPrefix
  //------------------------------
  protected $pathPrefix = '/';

  /**
   * Target path to serve.
   */
  public function pathPrefix($value = null) {
    $pathPrefix = $this->pathPrefix;

    if ( $value !== null ) {
      $this->pathPrefix = '/' . trim(trim($value), '/');
    }

    return $pathPrefix;
  }

  //--------------------------------------------------
  //
  //  Methods: IRequestResolver
  //
  //--------------------------------------------------

  public /* String */
  function resolve(Request $request, Response $response) {
    // skip when something is already done
    if ( $response->status() ) {
      return;
    }

    $path = $request->uri('path');

    // Request URI must start with the specified path prefix. e.g. /:resource/.
    if ( !$this->pathPrefix || 0 !== strpos($path, $this->pathPrefix) ) {
      return;
    }

    $res = substr($path, strlen($this->pathPrefix));
    $res = trim($res, '/');

    /*! devnote:
     *  todo: Use this format: /service/method1/arg1;arg2;arg3/method2/arg4;arg5...
     *
    // Resolve target service and apply appropiate parameters
    $res = explode('/', $res);

    $service = array_shift($res);
    if ( !class_exists('\services\\' . $service) ) {
      return; // 404
    }

    $service = "\services\\$service";
    if ( is_a($service, 'framework\WebService', true) ) {
      $service = new $service($request, $response);
    }
    else {
      $service = new $service();
    }

    if ( !($service instanceof \framework\interfaces\IWebService) ) {
      return;
    }

    $function = @array_slice(explode('\\', get_class($service)), -1)[0];

    // __invoke()
    if ( is_callable($service) ) {
      Log::info('[WebService] ' . $function, $res);
      $function = $service;
    }
    else {
      // note: no more args and not invokeable, throw.
      if ( !$res ) {
        $response->status(400); // Bad Request
        return;
      }

      Log::info('[WebService] ' . $function . '::' . $res[0], array_slice($res, 1));
      $function = array($service, array_shift($res));
    }

    unset($function);

    var_dump($service);

    $response->status(200);

    return;
    */
    // ------

    // Resolve target service and apply appropiate parameters
    preg_match('/^([^\/]+)(?:\/([^\/\?,]+))?(\/[^\?]+)?\/?/', $res, $matches);

    // Chain off to 404 instead of the original "501 Method Not Allowed".
    if ( count($matches) < 2 ) {
      return;
    }

    $classname = '\\services\\' . $matches[1];
    $function = @$matches[2];

    if ( !class_exists($classname) ) {
      return;
    }

    if ( is_a($classname, 'framework\WebService', true) ) {
      $instance = new $classname($request, $response);
    }
    else {
      $instance = new $classname();
    }

    if ( !($instance instanceof \framework\interfaces\IWebService) ) {
      return;
    }

    // If the class is invokeable, it takes precedence.
    if ( is_callable($instance) ) {
      if ( $function ) {
        @$matches[3] = "/$function$matches[3]";
      }

      $function = $instance; // self invoke
    }
    else if ( is_callable(array($instance, $function)) ) {
      $function = array($instance, $function);
    }
    else {
      $response->status(501); // Not implemented
      return;
    }

    if ( isset($matches[3]) ) {
      $parameters = array_map('rawurldecode', explode('/', trim($matches[3], '/')));
    }
    else {
      $parameters = array();
    }

    unset($matches);

    // Allow intelligible primitive values on REST requests
    $parameters = array_map(function($value) {
      if ( preg_match('/^\:([\d\w]+)$/', $value, $matches) ) {
        if ( strcasecmp(@$matches[1], 'true') == 0 ) {
          $value = true;
        }
        else if ( strcasecmp(@$matches[1], 'false') == 0 ) {
          $value = false;
        }
        else if ( strcasecmp(@$matches[1], 'null') == 0 ) {
          $value = null;
        }
      }

      return $value;
    }, $parameters);

    // Access log
    if ( System::environment() == 'debug' || !@$request->__local ) {
      Log::debug(sprintf('[WebService] %s %s->%s', $request->method(), $classname, is_array($function) ? end($function) : get_class($function)),
        array_filter(array('parameters' => $parameters)));
    }

    // Shooto!
    $serviceResponse = call_user_func_array($function, $parameters);

    // Return nothing when the service function returns nothing,
    // this favors file download and non-JSON outputs.
    if ( $serviceResponse !== null ) {
      // JSON encode the result and response to client.
      $response->header('Content-Type', 'application/json');
      $response->send($serviceResponse, $response->status() ? null : 200);
    }
    else if ( !$response->status() ) {
      $response->status(204);
    }
  }

}
