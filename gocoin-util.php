<?php
/**
 * GoCoin Utilities
 * 
 */
class Util
{
  public function sign($data, $key){
    //  $include = array('price_currency','base_price','base_price_currency','order_id','customer_name');
      $include = array('base_price','base_price_currency','order_id','customer_name');
      // $data must be an array
      if(is_array($data)) {

        $querystring = "";
        while(count($include) > 0) {
          $k = $include[0];
          if (isset($data[$k])) {
            $querystring .= $k . "=" . $data[$k] . "&";
            array_shift($include);
          }
          else {
            return false;
          }
        }

        //Strip trailing '&' and lowercase 
        $msg = substr($querystring, 0, strlen($querystring) - 1);
        $msg = strtolower($msg);

        // hash with key
        $hash = hash_hmac("sha256", $msg, $key, true);
        $encoded = base64_encode($hash);
        return $encoded;
      }
      else {
        return false;
      }
  }

  public function postData() {
      //get webhook content
      $response = new stdClass();
      $post_data = file_get_contents("php://input");

      if (!$post_data) {
        $response->error = 'Request body is empty';
      }

      $post_as_json = json_decode($post_data);
      if (is_null($post_as_json)){
        $response->error = 'Request body was not valid json';
      } else {
        $response = $post_as_json;
      }
      return $response;
  }
}
?>