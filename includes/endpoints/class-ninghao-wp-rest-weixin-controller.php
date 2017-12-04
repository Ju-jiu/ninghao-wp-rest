<?php

class Ninghao_WP_REST_Weixin_Controller extends WP_REST_Controller {
  public function __construct() {
    $this->namespace = 'weixin/v1';
  }

  public function register_routes() {
    register_rest_route( $this->namespace, '/bind', [
      'methods' => WP_REST_Server::EDITABLE,
      'callback' => [ $this, 'bind' ],
      'permission_callback' => [ $this, 'bind_permissions_check' ]
    ] );
  }

  public function bind_permissions_check( $request ) {
		$user = $this->get_user( $request['userId'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! empty( $request['roles'] ) ) {
			if ( ! current_user_can( 'promote_user', $user->ID ) ) {
				return new WP_Error( 'rest_cannot_edit_roles', __( 'Sorry, you are not allowed to edit roles of this user.' ), array( 'status' => rest_authorization_required_code() ) );
			}

			$request_params = array_keys( $request->get_params() );
			sort( $request_params );
			// If only 'id' and 'roles' are specified (we are only trying to
			// edit roles), then only the 'promote_user' cap is required.
			if ( $request_params === array( 'id', 'roles' ) ) {
				return true;
			}
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error( 'rest_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

  protected function get_user( $id ) {
		$error = new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );
		if ( (int) $id <= 0 ) {
			return $error;
		}

		$user = get_userdata( (int) $id );
		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
			return $error;
		}

		return $user;
	}

  public function bind( $request ) {
    $js_code = $request['code'];
    $user_id = $request['userId'];

    $session = $this->get_weixin_session( $js_code );
    if ( is_wp_error($session) ) {
      return $session;
    }
    $this->update_user_weixin_session( $user_id, $session );
    return 'ok';
  }

  public function update_user_weixin_session( $user_id, $session ) {
    update_user_meta( $user_id, 'wx_openid', $session['openid'] );
    update_user_meta( $user_id, 'wx_session_key', $session['session_key'] );
  }

  public function get_weixin_session( $js_code ) {
    $API_BASE = 'https://api.weixin.qq.com/sns/jscode2session';
    $APP_ID = env('WX_APP_ID');
    $SECRET = env('WX_APP_SECRET');
    $url = "$API_BASE?appid=$APP_ID&secret=$SECRET&js_code=$js_code&grant_type=authorization_code";

    $response = wp_remote_get( $url );
    $session = json_decode( $response['body'], true );

    if ( isset( $session['errcode'] ) ) {
      return new WP_Error(
        $session['errcode'],
        $session['errmsg'],
        [
          'status' => 400
        ]
      );
    }

    return $session;
  }
}
