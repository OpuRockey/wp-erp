<?php if ( current_user_can('erp_create_employee') ): ?>

<?php
$employees    = \WeDevs\ERP\HRM\Models\Employee::where('status', 'active')->count();
$departments  = \WeDevs\ERP\HRM\Models\Department::count();
$designations = \WeDevs\ERP\HRM\Models\Designation::count();
?>

<div class="badge-container">
    <div class="badge-wrap badge-green">
        <div class="badge-inner">
            <h3><?php echo number_format_i18n( $employees, 0 ); ?></h3>
            <p><?php _e( 'Employees', 'erp' ); ?></p>
        </div>

        <div class="badge-footer wp-ui-highlight">
            <a href="<?php echo erp_hr_employee_list_url(); ?>"><?php _e( 'View Employees', 'erp' ); ?></a>
        </div>
    </div><!-- .badge-wrap -->

    <div class="badge-wrap badge-red">
        <div class="badge-inner">
            <h3><?php echo number_format_i18n( $departments, 0 ); ?></h3>
            <p><?php _e( 'Departments', 'erp' ); ?></p>
        </div>
        <?php
        if ( is_admin() ) {
            ?>
            <div class="badge-footer wp-ui-highlight">
                <a href="<?php echo admin_url( 'admin.php?page=erp-hr&section=department' ); ?>"><?php _e( 'View Departments', 'erp' ); ?></a>
            </div>
            <?php
        }
        ?>

    </div><!-- .badge-wrap -->

    <div class="badge-wrap badge-aqua">
        <div class="badge-inner">
            <h3><?php echo number_format_i18n( $designations, 0 ); ?></h3>
            <p><?php _e( 'Designation', 'erp' ); ?></p>
        </div>
        <?php
        if ( is_admin() ) {
            ?>
            <div class="badge-footer wp-ui-highlight">
                <a href="<?php echo admin_url( 'admin.php?page=erp-hr&section=designation' ); ?>"><?php _e( 'View Designation', 'erp' ); ?></a>
            </div>
            <?php
        }
        ?>
    </div><!-- .badge-wrap -->
</div><!-- .badge-container -->

<?php endif ?>
