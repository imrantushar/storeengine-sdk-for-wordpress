<?php
// Paid packages never reach an unlicensed site, even from "check now".
$info = se_t_pro()->updater()->force_check();
se_t_assert( is_object( $info ) && '9.9.9' === $info->new_version, 'check now returns the new version' );
$cached = se_t_pro()->updater()->get_cached_update_info();
se_t_assert( $cached && empty( $cached->package ), 'cached info handed to the UI has no package for an unlicensed pro product' );
