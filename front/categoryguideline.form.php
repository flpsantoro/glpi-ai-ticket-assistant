<?php

/**
 * GLPI AI Ticket Assistant — saves the AI guidelines of an ITIL category.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\CategoryGuideline;
use GlpiPlugin\Ticketassistant\Compat;

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight(ITILCategory::$rightname, UPDATE);

$post        = Compat::rawPost();
$category_id = (int) ($post['itilcategories_id'] ?? 0);
$category    = new ITILCategory();

if (isset($post['save']) && $category_id > 0 && $category->getFromDB($category_id) && $category->canUpdateItem()) {
    CategoryGuideline::saveForCategory($category_id, (string) ($post['guidelines'] ?? ''), !empty($post['is_recursive']));
    Session::addMessageAfterRedirect(__('AI guidelines saved.', 'ticketassistant'), true, INFO);
}
Html::back();
