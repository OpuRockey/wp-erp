<?php
namespace WeDevs\ERP\HRM\API;

use WeDevs\ERP\HRM\Employee;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use WeDevs\ERP\API\REST_Controller;
use WeDevs\ERP\HRM\Models\Financial_Year;

class Leave_Entitlements_Controller extends REST_Controller {
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'erp/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'hrm/leaves/entitlements';

    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_leave_entitlements' ],
                'args'                => $this->get_collection_params(),
                /*'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_view_list' );
                },*/
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_entitlement' ],
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_leave_manage' );
                },
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_entitlement' ],
                'args'     => [
                    'context' => $this->get_context_param( [ 'default' => 'view' ] ),
                ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_view_list' );
                },
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_entitlement' ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_leave_manage' );
                },
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );
    }

    /**
    * Get a collection of entitlements
    *
    * @param WP_REST_Request $request
    *
    * @return WP_Error|WP_REST_Response
    */
    public function get_leave_entitlements ( \WP_REST_Request $request ) {


        $per_page       = $request->get_param( 'per_page' );
        $page           = $request->get_param( 'page' );
        $search         = $request->get_param( 'search' );
        $emp_status     = $request->get_param( 'emp_status' );
        $orderby        = $request->get_param( 'orderby' );
        $order          = $request->get_param( 'order' );
        $year           = $request->get_param( 'year' );
        $policy_id      = $request->get_param( 'policy_id' );

        $args = array(
            'offset'     => ( $per_page * ($page - 1 ) ),
            'number'     => $per_page,
            'emp_status' => isset( $emp_status ) ? $emp_status : 'active',
            'orderby'    => isset( $orderby ) ? $orderby : 'u.display_name',
            'order'      => isset( $order ) ? $order : 'DESC',
            'search'     => isset( $search ) ? $search : false,
            'year'       => isset( $year ) ? $year : 0,
            'policy_id'  => isset( $policy_id ) ? $policy_id : 0
        );

        $items     = erp_hr_leave_get_entitlements( $args );
        $total_items = 100;

        $formated_items = [];
        foreach ( $items['data'] as $item ) {
            $data             = $this->prepare_item_for_response( $item, $request );
            $formated_items[] = $this->prepare_response_for_collection( $data );
        }

        $response = rest_ensure_response( $formated_items );
        $response = $this->format_collection_response( $response, $request, $total_items );

        return $response;
    }

    /**
     * Get a specific entitlement
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_entitlement( $request ) {
        $id   = (int) $request['id'];
        $item = \WeDevs\ERP\HRM\Models\Leave_Entitlement::find( $id );

        if ( empty( $id ) || empty( $item->id ) ) {
            return new WP_Error( 'rest_policy_invalid_id', __( 'Invalid resource id.' ), [ 'status' => 404 ] );
        }

        $item     = $this->prepare_item_for_response( $item, $request );
        $response = rest_ensure_response( $item );

        return $response;
    }

    /**
     * Create an entitlement
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Request
     */
    public function create_entitlement( $request ) {
        $item = $this->prepare_item_for_database( $request );
        $id   = erp_hr_leave_insert_entitlement( $item );

        $entitlement = \WeDevs\ERP\HRM\Models\Leave_Entitlement::find( $id );

        $request->set_param( 'context', 'edit' );
        $response = $this->prepare_item_for_response( $entitlement, $request );
        $response = rest_ensure_response( $response );
        $response->set_status( 201 );
        $response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $id ) ) );

        return $response;
    }

    /**
     * Delete an entitlement
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Request
     */
    public function delete_entitlement( $request ) {
        $id = (int) $request['id'];

        $item        = \WeDevs\ERP\HRM\Models\Leave_Entitlement::find( $id );
        $employee_id = (int) $item->user_id;
        $policy_id   = (int) $item->policy_id;

        erp_hr_delete_entitlement( $id, $employee_id, $policy_id );

        return new WP_REST_Response( true, 204 );
    }

    /**
     * Prepare a single item for create or update
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return array $prepared_item
     */
    protected function prepare_item_for_database( $request ) {
        $prepared_item = [];

        // required arguments.
        if ( isset( $request['employee_id'] ) ) {
            $prepared_item['user_id'] = absint( $request['employee_id'] );
        }

        if ( isset( $request['policy'] ) ) {
            $prepared_item['policy_id'] = absint( $request['policy'] );
        }

        if ( isset( $request['days'] ) ) {
            $prepared_item['days'] = absint( $request['days'] );
        }

        if ( isset( $request['start_date'] ) ) {
            $prepared_item['from_date'] = date( 'Y-m-d', strtotime( $request['start_date'] ) );
        }

        if ( isset( $request['end_date'] ) ) {
            $prepared_item['to_date'] = date( 'Y-m-d', strtotime( $request['end_date'] ) );
        }

        return $prepared_item;
    }

    /**
     * Prepare a single user output for response
     *
     * @param object $item
     * @param WP_REST_Request $request Request object.
     * @param array $additional_fields (optional)
     *
     * @return WP_REST_Response $response Response data.
     */
    public function prepare_item_for_response( $item, $request, $additional_fields = [] ) {

        /*
        "id": "17",
        "user_id": "189",
        "leave_id": "1",
        "created_by": "1",
        "trn_id": "1",
        "trn_type": "leave_policies",
        "day_in": "15.0",
        "day_out": "0.0",
        "description": "",
        "f_year": "1",
        "created_at": "1593154752",
        "updated_at": "1593154752",
        "employee_name": "Nicholaus Ernser",
        "policy_name": "Casual Leave",
        "emp_status": "active"
         * */

        /*
      employee_name: "John doe",
      employee_designation: "Software Engineer",
      policy: "Leave policy",
      start_date: "April 07",
      end_date: "April 10",
      total_day: "3 Days",
      available: "5 Days",
      spend: "4 Days"
         */
        $employee = new Employee($item->user_id);
        $f_year = Financial_Year::find( $item->f_year );

        $data = [
            'id'             => (int) $item->id,
            'employee_id'    => (int) $item->user_id,
            'employee_name'  => $employee->display_name,
            'total_day'      => (int) $item->day_in,
            'available'      => (int) $item->day_in,
            'spend'          => (int) $item->day_in,
            'start_date'     => erp_format_date( $f_year->start_date ),
            'end_date'       => erp_format_date( $f_year->end_date ),
        ];

        if ( isset( $request['include'] ) ) {
            $include_params = explode( ',', str_replace( ' ', '', $request['include'] ) );

            if ( in_array( 'policy', $include_params ) ) {
                $policies_controller = new Leave_Policies_Controller();

                $policy_id  = (int) $item->policy_id;
                $data['policy'] = null;

                if ( $policy_id ) {
                    $policy = $policies_controller->get_policy( ['id' => $policy_id ] );
                    $data['policy'] = ! is_wp_error( $policy ) ? $policy->get_data() : null;
                }
            }
        }

        $data = array_merge( $data, $additional_fields );

        // Wrap the data in a response object
        $response = rest_ensure_response( $data );

        $response = $this->add_links( $response, $item );

        return $response;
    }

    /**
     * Get the User's schema, conforming to JSON Schema
     *
     * @return array
     */
    public function get_item_schema() {
        $schema = [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'policy',
            'type'       => 'object',
            'properties' => [
                'id'          => [
                    'description' => __( 'Unique identifier for the resource.' ),
                    'type'        => 'integer',
                    'context'     => [ 'embed', 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'employee_id'          => [
                    'description' => __( 'Employee id for the resource.' ),
                    'type'        => 'integer',
                    'context'     => [ 'embed', 'view', 'edit' ],
                    'required'    => true,
                ],
                'policy'          => [
                    'description' => __( 'Policy for the resource.' ),
                    'type'        => 'integer',
                    'context'     => [ 'embed', 'view', 'edit' ],
                    'required'    => true,
                ],
                'days'            => [
                    'description' => __( 'Days for the resource.' ),
                    'type'        => 'integer',
                    'context'     => [ 'embed', 'view', 'edit' ],
                    'required'    => true,
                ],
                'start_date'      => [
                    'description' => __( 'Start date for the resource.' ),
                    'type'        => 'string',
                    'context'     => [ 'edit' ],
                    'arg_options' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'required'    => true,
                ],
                'end_date'        => [
                    'description' => __( 'End date for the resource.' ),
                    'type'        => 'string',
                    'context'     => [ 'edit' ],
                    'arg_options' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'required'    => true,
                ],
            ],
        ];

        return $schema;
    }
}
