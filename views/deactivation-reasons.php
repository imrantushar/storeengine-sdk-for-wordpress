<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<div class="se-sdk-deactivation-modal--wrap reason">
		<div class="se-sdk-deactivation-modal--header">
			<h3><?php esc_html_e( 'If you have a moment, please let us know why you are deactivating:', 'absolute-addons' ); ?></h3>
			<a href="javascript:void 0;" class="se-sdk-deactivation-modal--close" aria-label="<?php esc_attr_e( 'Close', 'absolute-addons' ); ?>">
				<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24">
					<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path>
				</svg>
			</a>
		</div>
		<div class="se-sdk-deactivation-modal--body">
			<ul class="reasons">
				<?php foreach ( $reasons as $reason ) { ?>
					<li class="reason-item" data-type="<?php echo esc_attr( $reason['type'] ); ?>" data-placeholder="<?php echo esc_attr( $reason['placeholder'] ); ?>">
						<label>
							<input class="reason-type" type="radio" name="selected-reason" value="<?php echo esc_attr( $reason['id'] ); ?>"> <?php echo esc_html( $reason['text'] ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
			<div class="response" style="<?php echo ( $showSupportTicket ) ? 'display: block;' : ''; ?>">
				<div class="wrapper">
					<?php if ( $showSupportTicket ) { ?>
						<h3 style="font-size:15px;font-weight:600;margin:0;"><?php esc_html_e( 'In trouble?', 'absolute-addons' ); ?></h3>
						<p style="font-size:14px;margin:11px;"><?php esc_html_e( 'Please submit a support request.', 'absolute-addons' ); ?></p>
						<p>
							<a href="#" class="button button-secondary not-interested"><?php esc_html_e( 'Not Interested', 'absolute-addons' ); ?></a>
							<button class="button button-primary open-ticket-form"><?php esc_html_e( 'Open Support Ticket', 'absolute-addons' ); ?></button>
						</p>
					<?php } ?>
				</div>
			</div>
		</div>
		<div class="se-sdk-deactivation-modal--footer">
			<a href="#" class="button button-link dont-bother-me disabled"><?php esc_html_e( "I rather wouldn't say", 'absolute-addons' ); ?></a>
			<button class="button button-secondary deactivate disabled"><?php esc_html_e( 'Submit & Deactivate', 'absolute-addons' ); ?></button>
			<button class="button button-primary modal-close disabled"><?php esc_html_e( 'Cancel', 'absolute-addons' ); ?></button>
		</div>
	</div>
<?php
