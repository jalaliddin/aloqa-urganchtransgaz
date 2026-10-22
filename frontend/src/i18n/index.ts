import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import uz from './uz'

/**
 * UI strings. Domain labels (statuses, types) come from the backend /api/meta in the
 * user's locale, so adding Russian means: add ru.ts here and lang/ru/*.php on the backend.
 */
void i18n.use(initReactI18next).init({
  resources: { uz: { translation: uz } },
  lng: 'uz',
  fallbackLng: 'uz',
  interpolation: { escapeValue: false },
})

export default i18n
