<?php
class Seo_Product_Filter_Widget extends WP_Widget {

    const WIDGET_ID = __CLASS__;

    function __construct()
    {
        // Регистрация виджета в базе WP
        parent::__construct(self::WIDGET_ID, 'Фильтр', array(
            'description' => 'SEO-оптимизированный Фильтр WooCommerce'
        ) );

        add_action( 'before_sidebar', array( $this, 'sidebar_wrapper_start' ), 20 );
        add_action( 'after_sidebar',  array( $this, 'sidebar_wrapper_end' ), 5 );
    }

    static function register_widget(){
        register_widget( self::WIDGET_ID );
    }

    function sidebar_wrapper_start(){

        echo '<form action="'.get_permalink( wc_get_page_id('shop') ).'" method="get">';
    }
    function sidebar_wrapper_end(){
        $term_slug = '';
        $queried = get_queried_object();
        if( $queried instanceof WP_Term && $queried->taxonomy == 'product_cat' ) {
            $term_slug = $queried->slug;
        }

        echo '<input type="hidden" class="hidden hidden-xs-up" name="product_cat" value="'.$term_slug.'">';

        echo '</form>';
    }

    // Widget FrontEnd
    public function widget( $args, $instance ) {
        // title, attribute_id, relation, type..
        $option = array();
        $option['show_hidden'] = false;
        $option['show_count']  = false;

        $instance = wp_parse_args( $instance, array(
            'title'  => '',
            'attribute_id' => '',
            'relation' => 'OR',
            'widget' => 'filter',
            'type'   => 'checkbox',
        ) );

        $result = array();

        $result[] = $args['before_widget'];
        if( 'filter' === $instance['widget'] ){
            // set widget title
            if( $title = apply_filters( 'widget_title', $instance['title'] ) ) {
                $result[] = $args['before_title'] . $title . $args['after_title'];
            }

            // empty bugfix
            if( in_array($instance['attribute_id'], array('product_cat', 'product_tag')) ){
                $tax_args = $option['show_hidden'] ? array('hide_empty' => false) : array();
                if( wp_count_terms( $instance['attribute_id'], $tax_args ) < 1 ) {
                    return false;
                }
            }

            $terms = ( ! $option['show_hidden'] ) ?
                self::get_attribute_values( $instance['attribute_id'], 'id', true ) :
                self::get_attribute_values( $instance['attribute_id'] );

            // is not found
            if( sizeof( $terms ) < 1 ) {
                return false;
            }

            $filters = array();
            foreach ($terms as $term) {
                $label = ( $option['show_count'] ) ? $term->name . ' (' .$term->count. ')' : $term->name;


                $filters[] = array(
                    'id'    => $instance['attribute_id'] . '-' . $term->slug,
                    'name'  => apply_filters( 'parse_tax_name', $instance['attribute_id'] ) . '[]',
                    'value' => $term->term_id,
                    'label' => $label,
                    'type'  => $instance['type'],
                    );
            }

            global $wp_query;

            if( $tax = $wp_query->get( Seo_Product_Filter_Query::TAX ) ){
                $active = array( $tax => (int)$wp_query->get( Seo_Product_Filter_Query::TERM ) );
            }
            else {
                $active = $_GET;
            }

            ob_start();
            DTFilter\DTForm::render($filters, $active);
            $result[] = ob_get_clean();
        }
        else {
            ob_start();
            DTFilter\DTForm::render( array(
                array(
                    'type'  => 'submit',
                    'value' => 'Показать'
                ),
                array(
                    'type'  => 'hidden',
                    'value' => '1',
                    'name'  => 'filter',
                    )
            ) );
            $result[] = ob_get_clean();
        }
        $result[] = $args['after_widget'];

        echo implode("\r\n", $result);
    }

    public static function get_attribute_values( $taxonomy = '', $order_by = 'id', $hide_empty = false ) {
        if ( ! $taxonomy ) return array();
        $re = array();
        if( $hide_empty ) {
            global $wp_query, $post, $wp_the_query;
            $old_wp_the_query = $wp_the_query;
            $wp_the_query = $wp_query;
            if( method_exists('WC_Query', 'get_main_tax_query') && method_exists('WC_Query', 'get_main_tax_query') && 
            class_exists('WP_Meta_Query') && class_exists('WP_Tax_Query') ) {
                $args = array(
                    'orderby'    => $order_by,
                    'order'      => 'ASC',
                    'hide_empty' => false,
                );
                $re = get_terms( $taxonomy, $args );
                if( sizeof($re) < 1 ) return;
                global $wpdb;
                $meta_query = WC_Query::get_main_meta_query();
                $args      = $wp_the_query->query_vars;
                $tax_query = array();
                if ( ! empty( $args['product_cat'] ) ) {
                    $tax_query[ 'product_cat' ] = array(
                        'taxonomy' => 'product_cat',
                        'terms'    => array( $args['product_cat'] ),
                        'field'    => 'slug',
                    );
                }

                $meta_query      = new WP_Meta_Query( $meta_query );
                $tax_query       = new WP_Tax_Query( $tax_query );
                $meta_query_sql  = $meta_query->get_sql( 'post', $wpdb->posts, 'ID' );
                $tax_query_sql   = $tax_query->get_sql( $wpdb->posts, 'ID' );
                $term_ids = wp_list_pluck( $re, 'term_id' );

                // Generate query
                $query           = array();
                $query['select'] = "SELECT COUNT( DISTINCT {$wpdb->posts}.ID ) as term_count, terms.term_id as term_count_id";

                $query['from']   = "FROM {$wpdb->posts}";

                $query['join']   = "
                    INNER JOIN {$wpdb->term_relationships} AS term_relationships ON {$wpdb->posts}.ID = term_relationships.object_id
                    INNER JOIN {$wpdb->term_taxonomy} AS term_taxonomy USING( term_taxonomy_id )
                    INNER JOIN {$wpdb->terms} AS terms USING( term_id )
                    " . $tax_query_sql['join'] . $meta_query_sql['join'];

                $query['where']   = "
                    WHERE {$wpdb->posts}.post_type IN ( 'product' )
                    AND {$wpdb->posts}.post_status = 'publish'
                    " . $tax_query_sql['where'] . $meta_query_sql['where'] . "
                    AND terms.term_id IN (" . implode( ',', array_map( 'absint', $term_ids ) ) . ")
                ";

                $query['group_by'] = "GROUP BY terms.term_id";

                $query             = apply_filters( 'woocommerce_get_filtered_term_product_counts_query', $query );
                $query             = implode( ' ', $query );
                $results           = $wpdb->get_results( $query );
                $results           = wp_list_pluck( $results, 'term_count', 'term_count_id' );
                $term_count = array();
                $terms = array();
                foreach($re as &$res_count) {
                    if( ! empty($results[$res_count->term_id] ) ) {
                        $res_count->count = $results[$res_count->term_id];
                    } else {
                        $res_count->count = 0;
                    }
                    if( $res_count->count > 0 ) {
                        $terms[] = $res_count;
                    }
                }
                $re = $terms;
            } else {
                $terms = array();
                $q_args = $wp_query->query_vars;
                $q_args['posts_per_page'] = 2000;
                $q_args['post__in']       = '';
                $q_args['tax_query']      = '';
                $q_args['taxonomy']       = '';
                $q_args['term']           = '';
                $q_args['meta_query']     = '';
                $q_args['attribute']      = '';
                $q_args['title']          = '';
                $q_args['post_type']      = 'product';
                $q_args['fields']         = 'ids';
                $paged                    = 1;
                do{
                    $q_args['paged'] = $paged;
                    $the_query = new WP_Query($q_args);
                    if ( $the_query->have_posts() ) {
                        foreach ( $the_query->posts as $post_id ) {
                            $curent_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
                            foreach ( $curent_terms as $t ) {
                                if ( ! in_array( $t,$terms ) ) {
                                    $terms[] = $t;
                                }
                            }
                        }
                    }
                    $paged++;
                } while($paged <= $the_query->max_num_pages);
                unset( $q_args );
                unset( $the_query );
                wp_reset_query();
                $args = array(
                    'orderby'           => $order_by,
                    'order'             => 'ASC',
                    'hide_empty'        => false,
                );
                $terms2 = get_terms( $taxonomy, $args );
                foreach ( $terms2 as $t ) {
                    if ( in_array( $t->term_id, $terms ) ) {
                        $re[] = $t;
                    }
                }
            }
            $wp_the_query = $old_wp_the_query;
            return $re;
        } else {
            $args = array(
                'orderby'           => $order_by,
                'order'             => 'ASC',
                'hide_empty'        => false,
            );
            return get_terms( $taxonomy, $args );
        }
    }

    // Widget Backend
    function _widget_settings( $submit=false ){
        $tax_attributes = array();
        $form = array();
        // Созданные таксаномии "Атрибуты" (Без стандартных woocommerce)
        $attribs = get_object_taxonomies('product', 'objects');
        $default_product_type = array('product_type', 'product_shipping_class');
        if(sizeof($attribs) != 0){
            foreach ($attribs as $attr) {
                $attr_name = $attr->name;
                if(! in_array($attr_name, $default_product_type) ){
                    $tax_attributes[$attr_name] = $attr->label;
                }
            }
        }

        if(! $submit){
            $form = array(
                array(
                    'label' => 'Заголовок',
                    'data-title' => 'title',
                    'id'    => $this->get_field_id( 'title' ),
                    'name'  => $this->get_field_name( 'title' ),
                    'type'  => 'text',
                    'class' => 'widefat'
                    ),
                array(
                    'label'   => 'Аттрибут',
                    'data-title' => 'attribute_id',
                    'id'      => $this->get_field_id( 'attribute_id' ),
                    'name'    => $this->get_field_name( 'attribute_id' ),
                    'type'    =>'select',
                    'options' => $tax_attributes,
                    'class'   =>'widefat'
                    ),
                array(
                    'label'   => 'Логика',
                    'data-title' => 'relation',
                    'id'      => $this->get_field_id( 'relation' ),
                    'name'    => $this->get_field_name( 'relation' ),
                    'type'    =>'select',
                    'options' => array('OR' => __('OR'), 'AND' => __('AND') ),
                    'class'   =>'widefat'
                    ),
                // array(
                //  'label'   => 'Тип фильтра',
                //  'data-title' => 'type',
                //  'id'      => $this->get_field_id( 'type' ),
                //  'name'    => $this->get_field_name( 'type' ),
                //  'type'    =>'select',
                //  'options' => array('select' => 'Выбор', 'checkbox' => 'Чекбокс', 'radio' => 'Радио-кнопки'),
                //  'class'   =>'widefat'
                //  ),
                );
        } else {
            $form[] = array(
                'id'    => $this->get_field_id( 'title' ),
                'name'  => $this->get_field_name( 'title' ),
                'type'  => 'hidden',
                'class' => 'widefat',
                'value' => 'Кнопка "Показать"'
                );
        }

        $form[] = array(
            'label'   => 'Тип виджета',
            'data-title' => 'widget',
            'id'      => $this->get_field_id( 'widget' ),
            'name'    => $this->get_field_name( 'widget' ),
            'type'    =>'select',
            'options' => array('filter' => 'Фильтр', 'submit' => 'Кнопка фильтра продуктов'),
            'class'  =>'widefat',
            'before' => $submit ? '<strong>' : '<hr><strong>',
            'after'  => '</strong>'
            );

        return $form;
    }
    public function form( $instance ) {
        $form_instance = array();
        foreach ($instance as $key => $value) {
            $id = $this->get_field_name( $key );
            $form_instance[$id] = $value;
        }
        $submit = (end($form_instance) == 'submit') ? true : false;
        DTFilter\DTForm::render($this->_widget_settings($submit), $form_instance);
    }
    public function update( $new_instance, $old_instance ) {
        $instance = array();
        if($new_instance['widget'] != 'submit'){
            foreach ($this->_widget_settings() as $value) {
                $id = $value['data-title'];
                if( isset( $new_instance[$id] ) )
                    $instance[$id] = $new_instance[$id];
            }
        } else {
            $instance['widget'] = 'submit';
        }

        if( $old_instance !== $new_instance ) {
            flush_rewrite_rules();
        }

        return $instance;
    }
}