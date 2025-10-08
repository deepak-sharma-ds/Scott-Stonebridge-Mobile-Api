<?php
    
return [
    
    /*
        |--------------------------------------------------------------------------
        | Application Name
        |--------------------------------------------------------------------------
        |
        | This value is the name of your application. This value is used when the
        | framework needs to place the application's name in a notification or
        | any other location as required by the application or its packages.
        |
    */
    
    'name' => env('APP_NAME', 'Flavio'),
    
    
    'public' => [
	    'favicon' => 'media/img/logo/favicon.ico',
	    'fonts' => [
			'google' => [
				'families' => [	
					'Poppins:300,400,500,600,700'
				]
			]
		],
	    'global' => [
	    	'css' => [
		    	'vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
		    	'css/custom.css',
		    	'css/style.css',
		    ],
		    'js' => [
		    	'top'=>[
					'vendor/global/global.min.js',
					'vendor/bootstrap-select/dist/js/bootstrap-select.min.js',	
				],
				'bottom'=>[
					'js/deznav-init-min.js',
					'js/custom-min.js',
					'js/rdxjs-min.js',
				],
		    ],
	    ],
	    'pagelevel' => [
			'css' => [
				'PermissionsController_index' => [
					'css/acl-custom.css',
				],
				'PermissionsController_role_permissions' => [
					'css/acl-custom.css',
				],
				'PermissionsController_roles_permissions' => [
					'css/acl-custom.css',
				],
				'PermissionsController_user_permissions' => [
					'css/acl-custom.css',
				],
				'PermissionsController_manage_user_permissions' => [
					'css/acl-custom.css',
				],
				'PermissionsController_temp_permissions' => [
					'vendor/jstree/dist/themes/default/style.min.css',
				],

				'DashboardController_dashboard' => [
					'vendor/jqvmap/css/jqvmap.min.css',
		    		'vendor/owl-carousel/owl.carousel.css',
					'vendor/chartist/css/chartist.min.css',
				],
				'UsersController_index' => [
				],
				'UsersController_create' => [
				],
				'UsersController_edit' => [
				],

				'RolesController_index' => [
				],
				'RolesController_create' => [
				],
				'RolesController_edit' => [
				],

				'PagesController_admin_index' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'PagesController_admin_create' => [
					'vendor/pickadate/themes/default.css',
					'css/jquery-ui.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'PagesController_admin_edit' => [
					'vendor/pickadate/themes/default.css',
					'css/jquery-ui.css',
					'vendor/pickadate/themes/default.date.css',
				],

				'BlogsController_index' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'BlogsController_admin_index' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'BlogsController_admin_create' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
					'css/bootstrap-tagsinput.css'
				],
				'BlogsController_admin_edit' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
					'css/bootstrap-tagsinput.css'
				],

				'BlogCategoriesController_admin_index' => [
				],
				'BlogCategoriesController_admin_create' => [
				],
				'BlogCategoriesController_admin_edit' => [
				],

				'MenusController_admin_index' => [
					'vendor/nestable2/css/jquery.nestable.min.css'
				],
				'MenusController_admin_create' => [
				],
				'MenusController_admin_edit' => [
				],

				'MenuItemsController_admin_index' => [
				],
				'MenuItemsController_admin_create' => [
				],
				'MenuItemsController_admin_edit' => [
				],

				'ConfigurationsController_admin_prefix' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],

				'NotificationsController_index' => [
				],
				'NotificationsController_create' => [
				],
				'NotificationsController_edit' => [
				],
				'NotificationsController_edit_template' => [
				],
				'NotificationsController_edit_email_template' => [
				],
				'NotificationsController_edit_web_template' => [
				],
				'NotificationsController_edit_sms_template' => [
				],

				'W3CPTController_index' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3CPTController_index_taxo' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3CPTController_trash_list' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3CPTController_trash_taxo_list' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],

				/*W3apps module css start*/
				'W3appsController_index' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3appsController_themes' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3appsController_plugins' => [
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3appsController_upload_theme' => [
					'vendor/dropzone/dropzone.css',
					'css/bootstrap-tagsinput.css',
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				],
				'W3appsController_upload_plugin' => [
					'vendor/dropzone/dropzone.css',
					'css/bootstrap-tagsinput.css',
					'vendor/pickadate/themes/default.css',
					'vendor/pickadate/themes/default.date.css',
				]
				/*W3apps module css end*/

			],
		    'js' => [
				'PermissionsController_index' => [
				],
				'PermissionsController_role_permissions' => [
				],
				'PermissionsController_roles_permissions' => [
				],
				'PermissionsController_user_permissions' => [
				],
				'PermissionsController_manage_user_permissions' => [
				],
				'PermissionsController_temp_permissions' => [
					'vendor/jstree/dist/jstree.min.js',
				    'js/custom-min.js',
				],
			
				'DashboardController_dashboard' => [
				    'vendor/chart.js/Chart.bundle.min.js',
				    'vendor/peity/jquery.peity.min.js',
				    'vendor/apexchart/apexchart.js',
				    'js/dashboard/dashboard-min.js',
				    '/vendor/owl-carousel/owl.carousel.js',
				],
				'UsersController_index' => [
				],
				'UsersController_create' => [
				],
				'UsersController_edit' => [
				],
				'RolesController_index' => [
				],
				'RolesController_create' => [
				],
				'RolesController_edit' => [
				],

				'PagesController_admin_index' => [
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'PagesController_admin_create' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
					'js/jquery-slug-min.js',
					'js/pages-min.js',
					'js/jquery-ui.js',
					'js/magic_editor-min.js',
				],
				'PagesController_admin_edit' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
					'js/jquery-slug-min.js',
					'js/pages-min.js',
					'js/jquery-ui.js',
					'js/magic_editor-min.js',
				],

				'BlogsController_admin_index' => [
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],
				'BlogsController_admin_create' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
					'js/bootstrap-tagsinput.min.js',
				],
				'BlogsController_admin_edit' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
					'js/bootstrap-tagsinput.min.js',
				],

				'BlogCategoriesController_admin_index' => [
				],
				'BlogCategoriesController_admin_create' => [
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],
				'BlogCategoriesController_admin_edit' => [
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],

				'BlogTagsController_admin_create' => [
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],

				'BlogCategoriesController_list' => [
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],

				'BlogTagsController_list' => [
					'js/jquery-slug-min.js',
					'js/blogs-min.js',
				],

				'MenusController_admin_index' => [
					'vendor/nestable2/js/jquery.nestable.min.js',
					'js/menu-min.js',
				],
				'MenusController_admin_create' => [
				],
				'MenusController_admin_edit' => [
				],

				'MenuItemsController_admin_index' => [
				],
				'MenuItemsController_admin_create' => [
				],
				'MenuItemsController_admin_edit' => [
				],

				'ConfigurationsController_admin_prefix' => [
					'vendor/moment/moment.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],

				'NotificationsController_index' => [
				],
				'NotificationsController_create' => [
					'vendor/ckeditor/ckeditor.js',
				],
				'NotificationsController_edit' => [
					'vendor/ckeditor/ckeditor.js',
				],
				'NotificationsController_settings' => [
				],
				
				'NotificationsController_edit_template' => [
					'vendor/ckeditor/ckeditor.js',
				],
				'NotificationsController_edit_email_template' => [
					'vendor/ckeditor/ckeditor.js',
				],
				'NotificationsController_edit_web_template' => [
					'vendor/ckeditor/ckeditor.js',
				],
				'NotificationsController_edit_sms_template' => [
					'vendor/ckeditor/ckeditor.js',
				],

				'W3CPTController_index' => [
					'vendor/moment/moment.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3CPTController_index_taxo' => [
					'vendor/moment/moment.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3CPTController_trash_list' => [
					'vendor/moment/moment.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3CPTController_trash_taxo_list' => [
					'vendor/moment/moment.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],

				/*W3apps module js start*/
				'W3appsController_index' => [
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3appsController_themes' => [
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3appsController_plugins' => [
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3appsController_upload_theme' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/dropzone/dropzone-min.js',
					'js/bootstrap-tagsinput.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				],
				'W3appsController_upload_plugin' => [
					'vendor/ckeditor/ckeditor.js',
					'vendor/dropzone/dropzone-min.js',
					'js/bootstrap-tagsinput.min.js',
					'vendor/pickadate/picker.js',
					'vendor/pickadate/picker.date.js',
				]
				/*W3apps module js end*/
		    
		    ]
   		],
	]
];
