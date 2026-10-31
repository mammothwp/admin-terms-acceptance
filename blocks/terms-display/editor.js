/**
 * Editor script for mmwp/terms-display.
 *
 * Written against the wp.* globals with no JSX and no build step, matching the
 * rest of the plugin's assets. The preview is ServerSideRender because the
 * block's whole output comes from the archive — reimplementing it in JS would
 * give the editor a second, drifting copy of what the page actually shows.
 */
( function ( blocks, element, blockEditor, components, i18n, ServerSideRender ) {
	'use strict';

	if ( ! blocks || ! element ) {
		return;
	}

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'mmwp/terms-display', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var inspector = el(
				blockEditor.InspectorControls,
				null,
				el(
					components.PanelBody,
					{ title: __( 'Terms display', 'mammoth-acceptance-logs' ) },
					el( components.TextControl, {
						label: __( 'Heading', 'mammoth-acceptance-logs' ),
						help: __( 'Leave empty to show no heading.', 'mammoth-acceptance-logs' ),
						value: attributes.heading,
						onChange: function ( value ) {
							setAttributes( { heading: value } );
						},
					} ),
					el( components.TextControl, {
						label: __( 'Button label', 'mammoth-acceptance-logs' ),
						placeholder: __( 'Read the full terms', 'mammoth-acceptance-logs' ),
						value: attributes.buttonLabel,
						onChange: function ( value ) {
							setAttributes( { buttonLabel: value } );
						},
					} ),
					el( components.ToggleControl, {
						label: __( 'Show previous versions', 'mammoth-acceptance-logs' ),
						help: __(
							'Lists superseded versions below the current one. Their text stays available.',
							'mammoth-acceptance-logs'
						),
						checked: !! attributes.showHistory,
						onChange: function ( value ) {
							setAttributes( { showHistory: !! value } );
						},
					} ),
					attributes.showHistory
						? el( components.RangeControl, {
								label: __( 'Previous versions to list', 'mammoth-acceptance-logs' ),
								value: attributes.historyLimit,
								min: 1,
								max: 50,
								onChange: function ( value ) {
									setAttributes( { historyLimit: value || 10 } );
								},
						  } )
						: null
				)
			);

			var preview = ServerSideRender
				? el( ServerSideRender, {
						block: 'mmwp/terms-display',
						attributes: attributes,
						// Nothing is published yet, or the block is switched
						// off — say so rather than rendering an empty box.
						EmptyResponsePlaceholder: function () {
							return el(
								components.Placeholder,
								{ label: __( 'Terms & Conditions', 'mammoth-acceptance-logs' ) },
								__(
									'No terms version has been published yet. Publish one from the Terms settings screen.',
									'mammoth-acceptance-logs'
								)
							);
						},
				  } )
				: null;

			return el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
				inspector,
				preview
			);
		},

		// Dynamic block: the markup comes from the render callback.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
