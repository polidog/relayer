<?php

declare(strict_types=1);

/*
 * Framework Japanese catalog.
 *
 * Japanese has no grammatical plural, so pluralized keys carry a single
 * form (no `|`); Translator::transChoice() clamps the form index, so the
 * lone form is always used regardless of count.
 */

return [
    // Validation — base
    'relayer.validation.required' => '入力してください。',

    // Validation — string
    'relayer.validation.string.min' => '{min}文字以上で入力してください。',
    'relayer.validation.string.max' => '{max}文字以内で入力してください。',
    'relayer.validation.string.length' => '{length}文字で入力してください。',
    'relayer.validation.string.regex' => '形式が正しくありません。',
    'relayer.validation.string.email' => '有効なメールアドレスを入力してください。',
    'relayer.validation.string.url' => '有効なURLを入力してください。',
    'relayer.validation.string.type' => '文字列で入力してください。',

    // Validation — int
    'relayer.validation.int.min' => '{value}以上の値を入力してください。',
    'relayer.validation.int.max' => '{value}以下の値を入力してください。',
    'relayer.validation.int.positive' => '0より大きい値を入力してください。',
    'relayer.validation.int.non_negative' => '0以上の値を入力してください。',
    'relayer.validation.int.type' => '整数を入力してください。',

    // Validation — float
    'relayer.validation.float.min' => '{value}以上の値を入力してください。',
    'relayer.validation.float.max' => '{value}以下の値を入力してください。',
    'relayer.validation.float.positive' => '0より大きい値を入力してください。',
    'relayer.validation.float.non_negative' => '0以上の値を入力してください。',
    'relayer.validation.float.type' => '数値を入力してください。',

    // Validation — bool
    'relayer.validation.bool.true' => 'true である必要があります。',
    'relayer.validation.bool.type' => '真偽値で入力してください。',

    // Validation — array
    'relayer.validation.array.min' => '{min}個以上指定してください。',
    'relayer.validation.array.max' => '{max}個以内で指定してください。',
    'relayer.validation.array.non_empty' => '空にできません。',
    'relayer.validation.array.type' => '配列を指定してください。',

    // Validation — enum / object
    'relayer.validation.enum' => '次のいずれかを指定してください: {values}。',
    'relayer.validation.object.type' => 'オブジェクトを指定してください。',

    // HTTP reason phrases
    'relayer.http.400' => 'リクエストが不正です',
    'relayer.http.401' => '認証が必要です',
    'relayer.http.402' => '支払いが必要です',
    'relayer.http.403' => 'アクセスが拒否されました',
    'relayer.http.404' => 'ページが見つかりません',
    'relayer.http.405' => '許可されていないメソッドです',
    'relayer.http.406' => '受理できません',
    'relayer.http.408' => 'リクエストタイムアウト',
    'relayer.http.409' => '競合が発生しました',
    'relayer.http.410' => 'リソースは削除されました',
    'relayer.http.413' => 'ペイロードが大きすぎます',
    'relayer.http.415' => 'サポートされていないメディア形式です',
    'relayer.http.418' => '私はティーポットです',
    'relayer.http.422' => '処理できないエンティティです',
    'relayer.http.423' => 'ロックされています',
    'relayer.http.429' => 'リクエストが多すぎます',
    'relayer.http.451' => '法的理由により利用できません',
    'relayer.http.500' => 'サーバー内部エラー',
    'relayer.http.501' => '実装されていません',
    'relayer.http.502' => '不正なゲートウェイ',
    'relayer.http.503' => 'サービスを利用できません',
    'relayer.http.504' => 'ゲートウェイタイムアウト',
    'relayer.http.client_error' => 'クライアントエラー',
    'relayer.http.server_error' => 'サーバーエラー',
    'relayer.http.page_not_found' => 'ページが見つかりません',
];
