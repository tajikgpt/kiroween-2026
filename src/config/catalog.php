<?php
/**
 * Каталог провайдеров, моделей и пакетов.
 *
 * -----------------------------------------------------------------------------
 * ЭТО ПРИМЕР, А НЕ РАБОЧИЙ КАТАЛОГ
 * -----------------------------------------------------------------------------
 * Структура и вся арифметика здесь настоящие — на этом файле проходят
 * тесты из src/cli. Но закупочные цены (cost_*), имена поставщиков и
 * их идентификаторы моделей заменены на примерные: это коммерческие
 * данные, и в публичном репозитории им не место.
 *
 * Чтобы запустить у себя: подставьте своего провайдера в providers,
 * его настоящие цены в cost_* и пересчитайте coef_milli по формуле ниже.
 *
 * -----------------------------------------------------------------------------
 * БАЗОВАЯ СТАВКА: 1 000 000 токенов баланса = $0.05
 * -----------------------------------------------------------------------------
 *
 * Коэффициент считается так:
 *   blended = 0.81 × цена_входа + 0.19 × цена_выхода   (19% доля выхода)
 *   цена    = blended × 2                               (наценка ×2)
 *   coef    = цена / 0.05
 *
 * Хранится в тысячных: coef_milli = 9000 ⇒ ×9 ⇒ $0.45 за 1M токенов.
 *
 * cost_* — микродоллары за 1M токенов (1 мкд = 0.000001 USD).
 *
 * У части провайдеров цена ступенчатая по длине промпта, и высокая
 * ставка применяется ко ВСЕМУ запросу. Одним полем это не выражается,
 * поэтому для таких моделей пишется ставка верхней рабочей ступени:
 * иначе daily_cap_micro перестаёт быть настоящим потолком расхода.
 */

declare(strict_types=1);

return [

    // -------------------------------------------------------------------------
    'settings' => [
        // Микродолларов за 1M токенов баланса. Меняет цену всего каталога.
        'base_rate_micro_per_m' => 50000,
        // Минимальный баланс для обращения к бесплатной модели.
        // Без него регистрация превращается в бесконечный бесплатный API.
        'free_min_balance'      => 1000,
        // Через сколько секунд зависший резерв возвращается клиенту
        'reservation_ttl'       => 600,
        // Запросов в минуту на один ключ
        'rate_per_minute'       => 120,
        // Запас к оценке входных токенов перед резервом, в процентах
        'reserve_margin_pct'    => 15,

        // --- Резерв под выход ------------------------------------------------
        //
        // Раньше под выход резервировался весь max_tokens. Это выглядело
        // безопасно, но ломало работу: агенты ставят max_tokens: 64000
        // «на всякий случай», и на модели с коэффициентом ×9 шлюз требовал
        // заморозить 678 000 токенов там, где настоящий ответ занимал тысячу.
        // Клиент с балансом 100 000 не мог сделать ни одного запроса.
        //
        // Теперь резервируется доля от max_tokens, но не меньше нижней
        // границы. Недобор при расчёте допустим: sx_settle() доливает из
        // баланса и в минус не уходит, а остаток пишет в лог как наш убыток.
        // Защита от параллельных запросов сохраняется: вход резервируется
        // полностью, а у выхода есть нижняя граница.
        'reserve_out_pct'       => 25,
        'reserve_out_floor'     => 2048,
    ],

    // -------------------------------------------------------------------------
    // Имена поставщиков и адреса — примерные.
    'providers' => [
        'primary' => [
            'title'    => 'Primary',
            'base_url' => 'https://api.example-provider.test/v1',
            'key_env'  => 'PRIMARY_KEY',
            // Предохранитель: не больше $5 закупки в сутки. Утечка ключа
            // или цикл у клиента не должны выжечь предоплату за ночь.
            'daily_cap_micro' => 5_000_000,
            'priority' => 10,
            'active'   => true,
        ],
        'freetier' => [
            'title'    => 'Free tier',
            'base_url' => 'https://api.example-free.test/openai/v1',
            'key_env'  => 'FREETIER_KEY',
            'daily_cap_micro' => 0,     // бесплатный тариф
            'priority' => 20,
            'active'   => true,
        ],
        'spare' => [
            'title'    => 'Spare',
            'base_url' => 'https://api.example-spare.test/v1',
            'key_env'  => 'SPARE_KEY',
            'daily_cap_micro' => 2_000_000,
            'priority' => 30,
            'active'   => false,        // включить при необходимости
        ],
    ],

    // -------------------------------------------------------------------------
    // Публичные ID короткие и БЕЗ названий вендоров. Две причины:
    //
    //   1. Апстрим меняется в одной строке, клиентский код продолжает
    //      работать.
    //   2. Выдавать модель одного вендора под именем другого — обман
    //      покупателя. Нейтральное имя снимает сам повод так делать.
    //
    // ПЕРЕИМЕНОВАТЬ public_id после появления клиентов НЕЛЬЗЯ —
    // сломается их код. Выбирать сразу и навсегда.
    // -------------------------------------------------------------------------
    'models' => [

        'free' => [
            'title'       => 'Free Auto',
            'description' => 'Автовыбор доступной бесплатной модели',
            'provider'    => 'freetier',
            'upstream'    => 'vendor/open-70b',
            'coef_milli'  => 0,
            'cost_in_per_m' => 0, 'cost_out_per_m' => 0, 'cost_cached_per_m' => 0,
            'context'     => 131072, 'max_tokens' => 8192,
            'caps'        => ['tool_calling'],
            'fallback'    => 'fast',
            'featured'    => false, 'sort' => 10, 'active' => true,
        ],

        // ×3 → $0.15/M
        'fast' => [
            'title'       => 'Fast',
            'description' => 'Быстрая и дешёвая, для простых задач',
            'provider'    => 'primary',
            'upstream'    => 'vendor/small-20b',
            'coef_milli'  => 3000,
            'cost_in_per_m' => 40000, 'cost_out_per_m' => 200000, 'cost_cached_per_m' => 0,
            'context'     => 131072, 'max_tokens' => 32768,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'lite',
            'featured'    => true, 'sort' => 20, 'active' => true,
        ],

        // ×3 → $0.15/M
        'lite' => [
            'title'       => 'Lite',
            'description' => 'Длинный контекст по цене дешёвой модели',
            'provider'    => 'primary',
            'upstream'    => 'vendor/lite-long',
            'coef_milli'  => 3000,
            'cost_in_per_m' => 40000, 'cost_out_per_m' => 200000, 'cost_cached_per_m' => 8000,
            'context'     => 262144, 'max_tokens' => 32768,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'fast',
            'featured'    => false, 'sort' => 30, 'active' => true,
        ],

        // ×5 → $0.25/M
        'coder-lite' => [
            'title'       => 'Coder Lite',
            'description' => 'Дешёвая модель для кода',
            'provider'    => 'primary',
            'upstream'    => 'vendor/coder-30b',
            'coef_milli'  => 5000,
            'cost_in_per_m' => 67000, 'cost_out_per_m' => 335000, 'cost_cached_per_m' => 67000,
            'context'     => 262144, 'max_tokens' => 32768,
            'caps'        => ['tool_calling'],
            'fallback'    => 'coder',
            'featured'    => false, 'sort' => 40, 'active' => true,
        ],

        // Вход ×4, выход ×16 — раздельно.
        //
        // Модель рассуждающая: на ответ из одного слова приходило 233
        // выходных токена, 225 из них reasoning. Общий коэффициент на
        // такой нагрузке давал убыток, потому что выход у неё дороже
        // входа вчетверо.
        'agent' => [
            'title'       => 'Agent',
            'description' => 'Дешёвая для агентов: 256K контекста, изображения и видео',
            'provider'    => 'primary',
            'upstream'    => 'vendor/flash-multimodal',
            'coef_milli'  => 6280,
            'coef_in_milli'  => 4000,
            'coef_out_milli' => 16000,
            'cost_in_per_m' => 94000, 'cost_out_per_m' => 376000, 'cost_cached_per_m' => 19000,
            'context'     => 256000, 'max_tokens' => 32768,
            'caps'        => ['tool_calling', 'reasoning', 'vision'],
            'fallback'    => 'coder',
            'featured'    => true, 'sort' => 45, 'active' => true,
        ],

        // ×9 → $0.45/M. ОСНОВНАЯ ДЛЯ КОДА.
        'coder' => [
            'title'       => 'Coder',
            'description' => 'Основная модель для кода, контекст 1M',
            'provider'    => 'primary',
            'upstream'    => 'vendor/flash-coder',
            'coef_milli'  => 9000,
            'cost_in_per_m' => 120000, 'cost_out_per_m' => 600000, 'cost_cached_per_m' => 20000,
            'context'     => 1048576, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning', 'vision'],
            'fallback'    => 'coder-lite',
            'featured'    => true, 'sort' => 50, 'active' => true,
        ],

        // ×9 → $0.45/M.
        // Кэш-хит дешевле входа в разы — скидку клиенту не передаём,
        // это заложенный источник маржи. Решение осознанное: так же
        // устроены сопоставимые перепродавцы.
        'long' => [
            'title'       => 'Long',
            'description' => 'Очень длинный контекст, 1M токенов',
            'provider'    => 'primary',
            'upstream'    => 'vendor/long-context',
            'coef_milli'  => 9000,
            'cost_in_per_m' => 120000, 'cost_out_per_m' => 600000, 'cost_cached_per_m' => 3000,
            'context'     => 1048576, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'coder',
            'featured'    => false, 'sort' => 60, 'active' => true,
        ],

        // ×19 → $0.95/M
        'balanced' => [
            'title'       => 'Balanced',
            'description' => 'Сильнее базовых, дешевле топовых',
            'provider'    => 'primary',
            'upstream'    => 'vendor/mid-m3',
            'coef_milli'  => 19000,
            'cost_in_per_m' => 254000, 'cost_out_per_m' => 1270000, 'cost_cached_per_m' => 51000,
            'context'     => 262144, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'coder',
            'featured'    => false, 'sort' => 70, 'active' => true,
        ],

        // ×50 → $2.50/M
        'coder-pro' => [
            'title'       => 'Coder Pro',
            'description' => 'Для сложных агентных задач',
            'provider'    => 'primary',
            'upstream'    => 'vendor/pro-code',
            'coef_milli'  => 50000,
            'cost_in_per_m' => 667000, 'cost_out_per_m' => 3335000, 'cost_cached_per_m' => 134000,
            'context'     => 1048576, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'coder',
            'featured'    => false, 'sort' => 80, 'active' => true,
        ],

        // ×80 → $4.00/M
        'smart' => [
            'title'       => 'Smart',
            'description' => 'Сильнейшая открытая модель',
            'provider'    => 'primary',
            'upstream'    => 'vendor/large',
            'coef_milli'  => 80000,
            'cost_in_per_m' => 1068000, 'cost_out_per_m' => 5340000, 'cost_cached_per_m' => 214000,
            'context'     => 1048576, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning', 'vision'],
            'fallback'    => 'coder-pro',
            'featured'    => false, 'sort' => 90, 'active' => true,
        ],

        // Раздельные ставки: у этой модели выход в 8 раз дороже входа,
        // и общий коэффициент на агентной нагрузке с длинным выходом
        // даёт убыток. Вход ×53, выход ×420.
        'max' => [
            'title'       => 'Max',
            'description' => 'Топовая модель, самая дорогая',
            'provider'    => 'primary',
            'upstream'    => 'vendor/frontier',
            'coef_milli'  => 125000,
            'coef_in_milli'  => 53000,
            'coef_out_milli' => 420000,
            'cost_in_per_m' => 1245000, 'cost_out_per_m' => 9870000, 'cost_cached_per_m' => 125000,
            'context'     => 400000, 'max_tokens' => 65536,
            'caps'        => ['tool_calling', 'reasoning'],
            'fallback'    => 'smart',
            'featured'    => false, 'sort' => 100, 'active' => true,
        ],

        // --- Бесплатные и специальные ----------------------------------------

        // Бесплатно, провайдер не берёт за токены.
        //
        // ВНИМАНИЕ: поставщик прямо указывает, что промпты и ответы могут
        // сохраняться и использоваться для обучения, режима нулевого
        // хранения нет. Предупреждение вынесено в description, потому что
        // оно уходит клиенту в ответе /v1/models. Скрывать его нельзя:
        // человек, отправляющий сюда рабочий код, должен знать заранее.
        //
        // Подстановки при отказе нет намеренно: молча увести клиента
        // с бесплатной модели на платную значит списать за то, чего он
        // не выбирал.
        'alpha' => [
            'title'       => 'Alpha',
            'description' => 'Бесплатная экспериментальная: код и длинный контекст. '
                . 'ВНИМАНИЕ: провайдер сохраняет запросы и ответы и использует их '
                . 'для обучения — не отправляйте чувствительные данные. '
                . 'Ограничение 8 запросов в минуту',
            'provider'    => 'primary',
            'upstream'    => 'stealth/experimental',
            'coef_milli'  => 0,
            'cost_in_per_m' => 0, 'cost_out_per_m' => 0, 'cost_cached_per_m' => 0,
            'context'     => 256000, 'max_tokens' => 32768,
            'caps'        => ['tool_calling', 'reasoning'],
            'featured'    => false, 'sort' => 110, 'active' => true,
        ],

        // Бесплатно. Это классификатор безопасности, а не собеседник:
        // на запрос отвечает вердиктом вида "Safety: Safe / Categories: None".
        // Описание говорит об этом прямо, иначе клиент выберет модель
        // в ожидании диалога и получит разметку.
        //
        // Провайдер не объявляет размер контекста. Ставим 32K по базовой
        // модели, а не оставляем пустым: при отсутствии поля нормализатор
        // подставляет 128K, и мы пообещали бы вчетверо больше проверенного.
        'guard' => [
            'title'       => 'Guard',
            'description' => 'Проверка текста на небезопасное содержимое. '
                . 'Возвращает вердикт и категории, а не ответ на вопрос',
            'provider'    => 'primary',
            'upstream'    => 'vendor/guard-8b',
            'coef_milli'  => 0,
            'cost_in_per_m' => 0, 'cost_out_per_m' => 0, 'cost_cached_per_m' => 0,
            'context'     => 32768, 'max_tokens' => 1024,
            'caps'        => [],
            'featured'    => false, 'sort' => 120, 'active' => true,
        ],
    ],

    // -------------------------------------------------------------------------
    // Пакеты. Бонус за объём снижает маржу — это осознанный размен:
    // крупный пакет покупают реже, но он закрывает кассовый разрыв
    // на предоплате провайдеру.
    // -------------------------------------------------------------------------
    'packages' => [
        'try'   => ['title' => 'Проба',    'price_micro' =>   1_000_000, 'base_tokens' =>    20_000_000, 'bonus' =>  0, 'sort' => 10],
        'start' => ['title' => 'Старт',    'price_micro' =>   5_000_000, 'base_tokens' =>   100_000_000, 'bonus' => 10, 'sort' => 20],
        'work'  => ['title' => 'Рабочий',  'price_micro' =>  15_000_000, 'base_tokens' =>   300_000_000, 'bonus' => 17, 'sort' => 30],
        'pro'   => ['title' => 'Миллиард', 'price_micro' =>  50_000_000, 'base_tokens' => 1_000_000_000, 'bonus' => 25, 'sort' => 40],
        'max'   => ['title' => 'Максимум', 'price_micro' => 150_000_000, 'base_tokens' => 3_000_000_000, 'bonus' => 33, 'sort' => 50],
    ],
];
