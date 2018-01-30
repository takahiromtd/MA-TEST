<?php if ( ! defined( 'ABSPATH' ) || ! class_exists( 'NF_Abstracts_Action' )) exit;

/**
 * Class NF_Action_ZapierAction
 */
final class NF_Zapier_Actions_ZapierAction extends NF_Abstracts_Action {
    /**
     * @var string
     */
    protected $_name  = 'zapier';

    /**
     * @var array
     */
    protected $_tags = array('Zapier');

    /**
     * @var string
     */
    protected $_timing = 'late';

    /**
     * @var int
     */
    protected $_priority = '10';

    /**
     * Constructor
     */
    public function __construct() {
		parent::__construct();

		$this->_nicename = __( 'Zapier', 'ninja-forms-zapier' );
		
		$settings = array (	
			'zapier' => array(
				'name' => 'zapier-hook',
				'type' => 'textbox',
				'label' => __( 'Zapier Web Hook', 'ninja-forms-zapier' ),
				'placeholder' => __( 'Paste your Zapier Webhooks URL here', 'ninja-forms-zapier' ),
				'width' => 'full',
				'group' => 'primary',
				'use_merge_tags' => FALSE,
			)	
		
		);
		$this->_settings = array_merge ( $this->_settings, $settings );
		
	}

    /*
    * PUBLIC METHODS
    */
		
    public function process( $action_settings, $form_id, $data ) {

		$url = $action_settings['zapier-hook'];
		$fields = array();
		$duplicates = array();
		$field_data = $data['fields'];

		$hidden_field_types = apply_filters( 'nf_sub_hidden_field_types', array() );
		
		$fields['Date'] = date('Y-m-d H:i:s');
		
		//GET SEQUENCE NUMBER
		$sub_id = ( isset( $data[ 'actions' ][ 'save' ] ) ) ? $data[ 'actions' ][ 'save' ][ 'sub_id' ] : null;
		$fields['Sequence Number'] = Ninja_Forms()->form()->get_sub( $sub_id )->get_seq_num();
		
		forEach ( $field_data as $field_array ) {

			//CHECK FOR DATA THAT SHOULD BE HIDDEN
			if ( !in_array( $field_array['type'], $hidden_field_types ) ) {
				
				$label = $field_array['label'];
				
				//CHECK FOR DUPLICATE LABELS
				if ( array_key_exists( $label, $fields ) ) {
					
					if ( array_key_exists( $label, $duplicates ) ) {
						$duplicates[$label]++;
					} else {
						$duplicates[$label] = 2;
					}
					$label = $field_array['label'] . '_' . $duplicates[$label];
				}
								
				if ( $field_array['type'] === 'file_upload' ) {
					//SPECIAL CASE FOR FILE UPLOAD, FLATTEN ARRAY
					$fields[$label] = implode( ', ', apply_filters( 'nf_zapier_fu_field_value', $field_array['value'], $field_array ) );
				} else {
					$fields[$label] = $field_array['value'];
				}

				// CONVERT `false` TO `` (EMPTY STRING)
                if( false == $fields[$label] ){
                    $fields[$label] = '';
                }
			}
		}

		$result = ninja_forms_zapier_post_to_webhook($url, $fields);
				
		return $data;
	}
}
