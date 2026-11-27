// VitePress' default theme in TYPO3's corporate design: TYPO3 orange, Share
// for headings, Source Sans for text. The fonts are bundled (@fontsource), so
// the docs make no requests to font services.
import DefaultTheme from 'vitepress/theme-without-fonts'

import '@fontsource/share/400.css'
import '@fontsource/share/700.css'
import '@fontsource/source-sans-3/400.css'
import '@fontsource/source-sans-3/400-italic.css'
import '@fontsource/source-sans-3/600.css'
import '@fontsource/source-sans-3/700.css'
import '@fontsource/source-code-pro/400.css'
import './typo3.css'

export default DefaultTheme
