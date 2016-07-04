<?php
  set_time_limit(9000);

  $templatesURL = 'https://developer.mozilla.org/en-US/docs/templates?page=%d';
  $outputFolder = 'output';
  $templates = [];

  // Get number of pages using the macro
  const SEARCH_URL = 'https://developer.mozilla.org/en-US/search?locale=*&kumascript_macros=%s&topic=all&page=%d';
  const SEARCH_FILE_PATH = '%s/search%d.%s';

  if (!file_exists($outputFolder)) {
    mkdir($outputFolder);
  }

  for ($i = 1; $i <= 7; $i++) {
    $templateFilePath = $outputFolder . sprintf('/templates%d.html', $i);
    if (isset($_GET['refresh']) || !file_exists($templateFilePath)) {
      $fetchLocation = sprintf($templatesURL, $i);
    } else {
      $fetchLocation = $templateFilePath;
    }

    $response = file_get_contents($fetchLocation);

    if (isset($_GET['refresh']) || !file_exists($templateFilePath)) {
      file_put_contents($templateFilePath, $response);
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
      $templateFilePath = $outputFolder . '/' . $fileName;
      if (isset($_GET['refresh']) || !file_exists($templateFilePath)) {
        $templateFetchLocation = 'https://developer.mozilla.org' . $templateURL . '?raw';
      } else {
        $templateFetchLocation = $templateFilePath;
      }

      $templateResponse = file_get_contents($templateFetchLocation);
      $templates[$template] = [
        'name' => $templateName,
        'locale' => $locale,
        'fileName' => $fileName,
        'content' => $templateResponse
      ];

      if (isset($_GET['refresh']) || !file_exists($templateFilePath)) {
        file_put_contents($templateFilePath, $templateResponse);
      }

      $searchFilePath = sprintf(SEARCH_FILE_PATH, $outputFolder, 1, $fileName);
      if (isset($_GET['refresh']) || !file_exists($searchFilePath)) {        $searchFetchLocation = sprintf(SEARCH_URL, $template, 1);
      } else {
        $searchFetchLocation = $searchFilePath;
      }

      $searchResponse = file_get_contents($searchFetchLocation);

      $templates[$template]['searchResponses'] = [$searchResponse];

      if (isset($_GET['refresh']) || !file_exists($searchFilePath)) {
        file_put_contents($searchFilePath, $searchResponse);
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

  foreach($templatesCallingVariableTemplates as $templateName) {
    $numberOfSearchResultPages = ceil($templates[$templateName]['pageCount'] / 10);
    for($i = 1; $i <= $numberOfSearchResultPages; $i++) {
      if ($i === 1) {
        $searchResponse = $templates[$templateName]['searchResponses'][0];
      } else {
        $searchFilePath = sprintf(SEARCH_FILE_PATH, $outputFolder, $i, $templates[$templateName]['fileName']);
        if (isset($_GET['refresh']) || !file_exists($searchFilePath)) {
          // Fetch search result page
          $searchFetchLocation = sprintf(SEARCH_URL, $templateName, $i);
          $searchResponse = file_get_contents($searchFetchLocation);

          file_put_contents($searchFilePath, $searchResponse);
        } else {
          $searchResponse = file_get_contents($searchFilePath);
        }

        array_push($templates[$templateName]['searchResponses'], $searchResponse);

        // Get pages calling the template
        preg_match_all('/<h4>.*?<a href="(.*?\/([^\/]*?)\/docs\/.+?)".*?>(.+?)<\/a>/is', $searchResponse, $pageURLMatches);
        $pageURLMatchCount = count($pageURLMatches[0]);

        for($j = 0; $j < $pageURLMatchCount; $j++) {
          $locale = $pageURLMatches[2][$j];
          $pageName = $pageURLMatches[3][$j] . ($locale !== 'en-US' ? '.' . $locale : '');
          $fileName = substr(urlencode(str_replace(':', '_', $pageName)), 0, 180);
          $pageFilePath = $outputFolder . '/' . $fileName . '.html';
          if (isset($_GET['refresh']) || !file_exists($pageFilePath)) {
            // Fetch page calling template
            $pageResponse = file_get_contents($pageURLMatches[1][$j] . '?raw');
  
            file_put_contents($pageFilePath, $pageResponse);
          } else {
            $pageResponse = file_get_contents($pageFilePath);
          }

          preg_match_all('/\{\{\s*' . $templateName . '\([\'"](.+?)[\'"]/i', $pageResponse, $macroMatches);

          foreach($macroMatches[1] as $calledTemplateName) {
            if (!in_array($templateName, $templates[$calledTemplateName]['macros'])) {
              array_push($templates[$calledTemplateName]['macros'], $templateName);
            }
          }
        }
      }
    }
  }

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