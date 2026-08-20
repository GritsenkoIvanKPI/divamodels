#!/bin/sh
# Собирает архив для загрузки на хостинг: только то, что реально нужно сайту.
# В архив попадают все .html, assets/ и ТОЛЬКО те картинки, на которые есть
# ссылки в разметке — иначе туда уезжают старые исходники на десяток мегабайт.
#
# Запуск:  sh make-archive.sh
set -e
OUT="diva-models-site.zip"
LIST=".archive-files.txt"
rm -f "$OUT" "$LIST"

# html + assets
ls *.html > "$LIST"
find assets -type f ! -name '.DS_Store' >> "$LIST"

# только используемые картинки
for f in $(find images -type f ! -name '.DS_Store' | sed 's|^images/||'); do
  if grep -qF "images/$f" *.html assets/*.css assets/*.js 2>/dev/null; then
    echo "images/$f" >> "$LIST"
  fi
done

zip -@ "$OUT" < "$LIST" > /dev/null
rm -f "$LIST"

echo "Готово: $OUT"
unzip -l "$OUT" | tail -1
echo
echo "Не попали в архив (и не должны):"
echo "  send.php, api/, telegram-config*.php  — серверные, на статике не работают"
echo "  serve.mjs, screenshot.mjs, *.md, node_modules/  — рабочие файлы"
echo "  неиспользуемые картинки"
