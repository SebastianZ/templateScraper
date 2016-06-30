<?php
  set_time_limit(900);

  $templatesURL = 'https://developer.mozilla.org/en-US/docs/templates?page=%d';
  $outputFolder = 'output';
  $templates = [];

  if (!file_exists($outputFolder)) {
    mkdir($outputFolder);
  }

  for ($i = 1; $i <= 7; $i++) {
    $filePath = $outputFolder . sprintf('/templates%d.html', $i);
    if (isset($_GET['refresh']) || !file_exists($filePath)) {
      $fetchLocation = sprintf($templatesURL, $i);
    } else {
      $fetchLocation = $filePath;
    }

    $response = file_get_contents($fetchLocation);

    if (isset($_GET['refresh']) || !file_exists($filePath)) {
      file_put_contents($filePath, $response);
    }

    preg_match('/<ul class="document-list">.+?<\/ul>/s', $response, $templateListMatch);
    preg_match_all('/<a href="(.*?)">/', $templateListMatch[0], $templateURLMatches);

    // Fetch each template page
    foreach ($templateURLMatches[1] as $templateURL) {
      preg_match('/\/(.*?)\/docs\/Template:(.+)$/', $templateURL, $pageMatch);
      $templateName = $pageMatch[2];
      $locale = $pageMatch[1];
      $template = $templateName . ($locale !== 'en-US' ? '.' . $locale : '');
      $fileName = str_replace(':', '_', $template) . '.html';
      $filePath = $outputFolder . '/' . $fileName;
      if (isset($_GET['refresh']) || !file_exists($filePath)) {
        $templateFetchLocation = 'https://developer.mozilla.org' . $templateURL . '?raw';
      } else {
        $templateFetchLocation = $filePath;
      }

      $templateResponse = file_get_contents($templateFetchLocation);
      $templates[$template] = [
        'name' => $templateName,
        'locale' => $locale,
        'fileName' => $fileName,
        'content' => $templateResponse
      ];

      if (isset($_GET['refresh']) || !file_exists($filePath)) {
        file_put_contents($filePath, $templateResponse);
      }

      // Get number of pages using the macro
      $searchURL = 'https://developer.mozilla.org/en-US/search?locale=*&kumascript_macros=%s&topic=all';

      $filePath = $outputFolder . '/search.' . $fileName;
      if (isset($_GET['refresh']) || !file_exists($filePath)) {
        $searchFetchLocation = sprintf($searchURL, $template);
      } else {
        $searchFetchLocation = $filePath;
      }

      $searchResponse = file_get_contents($searchFetchLocation);

      if (isset($_GET['refresh']) || !file_exists($filePath)) {
        file_put_contents($filePath, $searchResponse);
      }

      preg_match('/(\d+) documents? found/', $searchResponse, $searchResultCount);
      $templates[$template]['pageCount'] = (int)$searchResultCount[1];
    }
  }

  // Get number of macros using the macro
  $templatesCallingVariableTemplates = [];
  $templateNames = array_keys($templates);
  foreach ($templateNames as $templateName) {
    $templates[$templateName]['macros'] = [];
    foreach ($templates as $name => $template) {
      if ($templateName === $name) {
        continue;
      }

      if (preg_match('/template\(\s*["\']' . $templateName . '/i', $template['content'])) {
        array_push($templates[$templateName]['macros'], urldecode($name));
      }

      // Check whether template contains template($0, ...) call
      if (!in_array($name, $templatesCallingVariableTemplates) && preg_match('/template\(\$0/', $template['content'])) {
        array_push($templatesCallingVariableTemplates, $name);
      }
    }
  }

  // Output search results
  $searchResultsFilePath = $outputFolder . '/searchResults.html';
  file_put_contents($searchResultsFilePath, '<!DOCTYPE html><head><meta charset="utf-8"/></head>' .
      '<style>table{border-collapse:collapse;}th,td{border:1px solid black;padding:3px;vertical-align:top;}.unused{background:#ffb4b4;}.onlyUsedByOneMacro{background:#ffffb4;}</style>' .
      '<table><thead><tr><th>Template</th><th>Page count</th><th>Macros</th></tr></thead><tbody>');

  foreach ($templates as $template) {
    $class = '';
    if ($template['pageCount'] === 0) {
      if (count($template['macros']) === 0) {
        $class = ' class="unused"';
      } else if (count($template['macros']) === 1) {
        $class = ' class="onlyUsedByOneMacro"';
      }
    }

    file_put_contents($searchResultsFilePath, sprintf('<tr%s><td><a href="https://developer.mozilla.org/%s/docs/Template:%s">%s</a></td><td>%s</td><td>%s</td></tr>',
        $class, $template['locale'], $template['name'], urldecode($template['name']), $template['pageCount'], implode($template['macros'], '<br/>')), FILE_APPEND);
  }

  file_put_contents($searchResultsFilePath, '</tbody></table>', FILE_APPEND);
?>