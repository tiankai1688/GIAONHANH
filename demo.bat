@echo off
chcp 65001 >nul
REM ============================================================
REM GIAONHANH Docker 真机 demo 一键起栈（Windows 双击运行）
REM 前置：已安装并启动 Docker Desktop（托盘鲸鱼图标变绿）
REM 作用：构建并启动后端容器 + 建表 + 灌演示数据 + 验活
REM 录制部分（改演示页 / OBS 录屏）仍需手动，见末尾提示
REM ============================================================
cd /d "%~dp0"

echo.
echo [准备] 请确认 Docker Desktop 已启动（系统托盘鲸鱼图标为绿色）
echo.

echo [1/4] 构建并后台启动 Docker 栈（首次约 3-5 分钟，请耐心等待）...
docker compose -p giaonhanh up --build -d
if errorlevel 1 (
  echo [错误] docker compose -p giaonhanh 启动失败，请确认 Docker Desktop 已运行后再试。
  pause
  exit /b 1
)

echo [2/4] 等待数据库与后端就绪（约 20 秒）...
timeout /t 20 >nul

echo [3/4] 建表 + 灌入演示数据（3 商家/商品 + 账号）...
docker compose -p giaonhanh exec -T app php artisan migrate --force
docker compose -p giaonhanh exec -T app php artisan db:seed

echo [4/4] 验证后端活体（应返回 {"ok":true}）...
curl -s http://127.0.0.1:8080/api/v1/health
echo.

echo ============================================================
echo 起栈完成！接下来请手动完成录制部分：
echo.
echo  1) 复制 app\pay-demo.html 为 app\pay-demo-live.html
echo     用记事本打开，把 window.GN_CONFIG 改成：
echo       apiBase: "http://127.0.0.1:8080", useApi: true
echo  2) 打开 Git Bash，进入 app 目录，运行：python3 -m http.server 5173
echo  3) 浏览器打开 http://localhost:5173/pay-demo-live.html
echo  4) 用 OBS 照 docs\demo-guide.md 的脚本表录 3-5 分钟
echo  5) 录完在另一个窗口运行：docker compose -p giaonhanh down（关闭栈）
echo ============================================================
pause
