<?php
namespace WeDevs\ERP\HRM\API;

use WeDevs\ERP\API\REST_Controller;
use WeDevs\ERP\HRM\Employee;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;

class Leave_Requests_Controller extends REST_Controller {
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
    protected $rest_base = 'hrm/leaves/requests';

    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_leave_requests' ],
                'args'                => $this->get_collection_params(),
                /*'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_view_list' );
                },*/
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_leave_request' ],
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
               /* 'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_leave_manage' );
                },*/
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_leave_request' ],
                'args'                => [
                    'context' => $this->get_context_param( [ 'default' => 'view' ] ),
                ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_list_employee' );
                },
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/action', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'leave_request_action' ],
                'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                /*'permission_callback' => function ( $request ) {
                    return current_user_can( 'erp_list_employee' );
                },*/
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );

    }

    /**
     * Get a collection of leave requests
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_leave_requests( \WP_REST_Request $request ) {

        $per_page       = $request->get_param( 'per_page' );
        $page           = $request->get_param( 'page' );
        $status         = $request->get_param( 'status' );
        $filter_year    = $request->get_param( 'filter_year' );
        $orderby        = $request->get_param( 'orderby' );
        $order          = $request->get_param( 'order' );
        $search         = $request->get_param( 's' );

        // get current year as default f_year
        $f_year = erp_hr_get_financial_year_from_date();
        $f_year = ! empty( $f_year ) ? $f_year->id : '';

        $args = array(
            'offset'  => ( $per_page * ( $page - 1 ) ),
            'number'  => $per_page,
            'status'  => $status,
            'f_year'  => isset( $filter_year ) ? $filter_year : $f_year,
            'orderby' => isset( $orderby ) ? $orderby : 'created_at',
            'order'   => isset( $order ) ? $order : 'DESC',
            's'       => isset( $search ) ? $search : ''
        );

        if ( erp_hr_is_current_user_dept_lead() && ! current_user_can( 'erp_leave_manage' ) ) {
            $args['lead'] = get_current_user_id();
        }

        $leave_requests = erp_hr_get_leave_requests( $args );
        $items          = $leave_requests['data'];
        $total          = $leave_requests['total'];

        $formatted_items = [];
        foreach( $items as $item ) {
            $data              = $this->prepare_item_for_response( $item, $request );
            $formatted_items[] = $this->prepare_response_for_collection( $data );
        }

        $response = rest_ensure_response( $formatted_items );
        $response = $this->format_collection_response( $response, $request, $total );

        return $response;

    }

    /**
     * Get a specific leave request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_leave_request( $request ) {
        $id   = (int) $request['id'];
        $item = erp_hr_get_leave_request( $id );

        if ( empty( $id ) || empty( $item->id ) ) {
            return new WP_Error( 'rest_leave_request_invalid_id', __( 'Invalid resource id.' ), [ 'status' => 404 ] );
        }

        $item     = $this->prepare_item_for_response( $item, $request );
        $response = rest_ensure_response( $item );

        return $response;
    }

    /**
     * Create a leave request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Request
     */
    public function create_leave_request( \WP_REST_Request $request ) {
        $employee_id     = $request->get_param( 'employee_id' );
        $leave_policy    = $request->get_param( 'leave_policy' );
        $leave_from      = $request->get_param( 'leave_from' );
        $leave_to        = $request->get_param( 'leave_to' );
        $leave_reason    = $request->get_param( 'leave_reason' );

        $response = erp_hr_leave_insert_request( array(
            'user_id'      => $employee_id,
            'leave_policy' => $leave_policy,
            'start_date'   => $leave_from,
            'end_date'     => $leave_to,
            'reason'       => $leave_reason
        ) );

        $item     = $this->prepare_item_for_response( $response, $request );
        return rest_ensure_response( $item );
    }


    /**
     * Approve OR Reject leave requests
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function leave_request_action( \WP_REST_Request $request ) {

        $id         = $request->get_param( 'id' );
        $reason     = $request->get_param( 'reason' );
        $type       = $request->get_param( 'type' );

        if ( isset( $id ) && ! empty( $id ) ) {
            switch ( $type ) {
                case 'approved':
                    $status = 1;
                    break;

                case 'pending':
                    $status = 2;
                    break;

                case 'rejected':
                    $status = 3;
                    break;

                case 'forwarded':
                    $status = 4;
                    break;

                default:
                    $status = 3;
                    break;
            }
            $response = erp_hr_leave_request_update_status( $id, $status, $reason );
            return rest_ensure_response( $response );
        }
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

        if ( isset( $request['start_date'] ) ) {
            $prepared_item['start_date'] = date( 'Y-m-d', strtotime( $request['start_date'] ) );
        }

        if ( isset( $request['end_date'] ) ) {
            $prepared_item['end_date'] = date( 'Y-m-d', strtotime( $request['end_date'] ) );
        }

        if ( isset( $request['policy'] ) ) {
            $prepared_item['leave_policy'] = absint( $request['policy'] );
        }

        // optional arguments.
        if ( isset( $request['id'] ) ) {
            $prepared_item['id'] = absint( $request['id'] );
        }

        if ( isset( $request['reason'] ) ) {
            $prepared_item['reason'] = $request['reason'];
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
        $employee = new Employee($item->user_id);

        $data = [
            'id'                   => (int)$item->id,
            'user_id'              => (int)$item->user_id,
            'employee_id'          => (int)$employee->employee_id,
            'employee_name'        => $employee->display_name,
            'display_name'         => $item->display_name,
            'employee_designation' => $employee->get_designation('view'),
            'policy_name'          => $item->policy_name,
            'avatar_url'           => $employee->get_avatar_url(80),
            'start_date'           => erp_format_date($item->start_date, 'Y-m-d'),
            'end_date'             => erp_format_date($item->end_date, 'Y-m-d'),
            'reason'               => $item->reason,
            'message'              => $item->message,
            'leave_id'             => $item->leave_id,
            'days'                 => $item->days,
            'available'            => $item->available,
            'spent'                => $item->spent
        ];

        $data = array_merge( $data, $additional_fields );

        // Wrap the data in a response object
        $response = rest_ensure_response( apply_filters( 'filter_leave_request', $data, $request ) );

        $response = $this->add_links( $response, $item );

        return $response;
    }
}
