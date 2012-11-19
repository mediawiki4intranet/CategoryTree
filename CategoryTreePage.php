<?php
/**
 * Special page for the  CategoryTree extension, an AJAX based gadget
 * to display the category structure of a wiki
 *
 * @file
 * @ingroup Extensions
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2006 Daniel Kinzler
 * @license GNU General Public Licence 2.0 or later
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is part of an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

class CategoryTreePage extends SpecialPage {
	var $target = '';
	var $tree = null;

	function __construct() {
		parent::__construct( 'CategoryTree', '', true );
	}

	/**
	 * @param $name
	 * @return mixed
	 */
	function getOption( $name ) {
		global $wgCategoryTreeDefaultOptions;

		if ( $this->tree ) {
			return $this->tree->getOption( $name );
		} else {
			return $wgCategoryTreeDefaultOptions[$name];
		}
	}

	/**
	 * Main execution function
	 * @param $par array Parameters passed to the page
	 */
	function execute( $par ) {
		global $wgCategoryTreeDefaultOptions, $wgCategoryTreeSpecialPageOptions, $wgCategoryTreeForceHeaders;

		$this->setHeaders();
		$request = $this->getRequest();
		if ( $par ) {
			$this->target = $par;
		} else {
			$this->target = $request->getVal( 'target', wfMessage( 'rootcategory' )->text() );
		}

		$this->target = trim( $this->target );

		# HACK for undefined root category
		if ( $this->target == '<rootcategory>' || $this->target == '&lt;rootcategory&gt;' ) {
			$this->target = null;
		}

		$options = array();

		# grab all known options from the request. Normalization is done by the CategoryTree class
		foreach ( $wgCategoryTreeDefaultOptions as $option => $default ) {
			if ( isset( $wgCategoryTreeSpecialPageOptions[$option] ) ) {
				$default = $wgCategoryTreeSpecialPageOptions[$option];
			}

			$options[$option] = $request->getVal( $option, $default );
		}

		$this->tree = new CategoryTree( $options );

		$output = $this->getOutput();
		$output->addWikiMsg( 'categorytree-header' );

		$this->executeInputForm();

		if ( $this->target !== '' && $this->target !== null ) {
			if ( !$wgCategoryTreeForceHeaders ) {
				CategoryTree::setHeaders( $output );
			}

			$title = CategoryTree::makeTitle( $this->target );

			if ( $title && $title->getArticleID() ) {
				$output->addHTML( Xml::openElement( 'div', array( 'class' => 'CategoryTreeParents' ) ) );
				$output->addHTML( wfMessage( 'categorytree-parents' )->parse() );
				$output->addHTML( wfMessage( 'colon-separator' )->escaped() );

				$parents = $this->tree->renderParents( $title );

				if ( $parents == '' ) {
					$output->addHTML( wfMessage( 'categorytree-no-parent-categories' )->parse() );
				} else {
					$output->addHTML( $parents );
				}

				$output->addHTML( Xml::closeElement( 'div' ) );

				$output->addHTML( Xml::openElement( 'div', array( 'class' => 'CategoryTreeResult' ) ) );
				$output->addHTML( $this->tree->renderNode( $title, 1 ) );
				$output->addHTML( Xml::closeElement( 'div' ) );
			} else {
				$output->addHTML( Xml::openElement( 'div', array( 'class' => 'CategoryTreeNotice' ) ) );
				$output->addHTML( wfMessage( 'categorytree-not-found', $this->target )->parse() );
				$output->addHTML( Xml::closeElement( 'div' ) );
			}
		}
	}

	/**
	 * Input form for entering a category
	 */
	function executeInputForm() {
		global $wgScript;
		$thisTitle = SpecialPage::getTitleFor( $this->getName() );
		$namespaces = $this->getRequest()->getVal( 'namespaces', '' );
		//mode may be overriden by namespaces option
		$mode = ( $namespaces == '' ? $this->getOption( 'mode' ) : CT_MODE_ALL );
		$modeSelector = Xml::openElement( 'select', array( 'name' => 'mode' ) );
		$modeSelector .= Xml::option( wfMessage( 'categorytree-mode-categories' )->plain(), 'categories', $mode == CT_MODE_CATEGORIES );
		$modeSelector .= Xml::option( wfMessage( 'categorytree-mode-pages' )->plain(), 'pages', $mode == CT_MODE_PAGES );
		$modeSelector .= Xml::option( wfMessage( 'categorytree-mode-all' )->plain(), 'all', $mode == CT_MODE_ALL );
		$modeSelector .= Xml::closeElement( 'select' );
		$table = Xml::buildForm( array(
			'categorytree-category' => Xml::input( 'target', 20, $this->target, array( 'id' => 'target' ) ) ,
			'categorytree-mode-label' => $modeSelector,
			'namespace' => self::namespaceSelector(
				array( 'selected' => $namespaces, 'all' => '' ),
				array( 'name' => 'namespaces', 'id' => 'namespaces' )
			)
		), 'categorytree-go' );
		$preTable = Xml::element( 'legend', null, wfMessage( 'categorytree-legend' )->plain() );
		$preTable .= Html::Hidden( 'title', $thisTitle->getPrefixedDbKey() );
		$fieldset = Xml::tags( 'fieldset', array(), $preTable . $table );
		$output = $this->getOutput();
		$output->addHTML( Xml::tags( 'form', array( 'name' => 'categorytree', 'method' => 'get', 'action' => $wgScript, 'id' => 'mw-categorytree-form' ), $fieldset ) );
	}

	/**
	 * Build a drop-down box for selecting a namespace
	 * Back-ported into 1.18
	 *
	 * @param $params array:
	 * - selected: [optional] Id of namespace which should be pre-selected
	 * - all: [optional] Value of item for "all namespaces". If null or unset, no "<option>" is generated to select all namespaces
	 * - label: text for label to add before the field
	 * - exclude: [optional] Array of namespace ids to exclude
	 * - disable: [optional] Array of namespace ids for which the option should be disabled in the selector
	 * @param $selectAttribs array HTML attributes for the generated select element.
	 * - id:   [optional], default: 'namespace'
	 * - name: [optional], default: 'namespace'
	 * @return string HTML code to select a namespace.
	 */
	public static function namespaceSelector( array $params = array(), array $selectAttribs = array() ) {
		global $wgContLang;

		ksort( $selectAttribs );

		// Is a namespace selected?
		if ( isset( $params['selected'] ) ) {
			// If string only contains digits, convert to clean int. Selected could also
			// be "all" or "" etc. which needs to be left untouched.
			// PHP is_numeric() has issues with large strings, PHP ctype_digit has other issues
			// and returns false for already clean ints. Use regex instead..
			if ( preg_match( '/^\d+$/', $params['selected'] ) ) {
				$params['selected'] = intval( $params['selected'] );
			}
			// else: leaves it untouched for later processing
		} else {
			$params['selected'] = '';
		}

		if ( !isset( $params['exclude'] ) || !is_array( $params['exclude'] ) ) {
			$params['exclude'] = array();
		}
		if ( !isset( $params['disable'] ) || !is_array( $params['disable'] ) ) {
			$params['disable'] = array();
		}

		// Associative array between option-values and option-labels
		$options = array();

		if ( isset( $params['all'] ) ) {
			// add an option that would let the user select all namespaces.
			// Value is provided by user, the name shown is localized for the user.
			$options[$params['all']] = wfMessage( 'namespacesall' )->text();
		}
		// Add all namespaces as options (in the content langauge)
		$options += $wgContLang->getFormattedNamespaces();

		// Convert $options to HTML and filter out namespaces below 0
		$optionsHtml = array();
		foreach ( $options as $nsId => $nsName ) {
			if ( $nsId < NS_MAIN || in_array( $nsId, $params['exclude'] ) ) {
				continue;
			}
			if ( $nsId === NS_MAIN ) {
				// For other namespaces use use the namespace prefix as label, but for
				// main we don't use "" but the user message descripting it (e.g. "(Main)" or "(Article)")
				$nsName = wfMessage( 'blanknamespace' )->text();
			} elseif ( is_int( $nsId ) ) {
				$nsName = $wgContLang->getNsText( $nsId );
			}
			$optionsHtml[] = Html::element(
				'option', array(
					'disabled' => in_array( $nsId, $params['disable'] ),
					'value' => $nsId,
					'selected' => $nsId === $params['selected'],
				), $nsName
			);
		}

		if ( !array_key_exists( 'id', $selectAttribs ) ) {
			$selectAttribs['id'] = 'namespace';
		}

		if ( !array_key_exists( 'name', $selectAttribs ) ) {
			$selectAttribs['name'] = 'namespace';
		}

		$ret = '';
		if ( isset( $params['label'] ) ) {
			$ret .= Html::element(
				'label', array(
					'for' => isset( $selectAttribs['id'] ) ? $selectAttribs['id'] : null,
				), $params['label']
			) . '&#160;';
		}

		// Wrap options in a <select>
		$ret .= Html::openElement( 'select', $selectAttribs )
			. "\n"
			. implode( "\n", $optionsHtml )
			. "\n"
			. Html::closeElement( 'select' );

		return $ret;
	}
}
