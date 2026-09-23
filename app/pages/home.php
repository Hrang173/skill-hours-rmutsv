<?php
redirect(current_user() ? home_path() : '/login');
